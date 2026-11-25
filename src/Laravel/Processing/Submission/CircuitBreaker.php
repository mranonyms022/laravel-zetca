<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Processing\Submission;

use Illuminate\Contracts\Cache\Repository;

/**
 * Per-environment circuit breaker for ZATCA being unavailable (ARCHITECTURE 15.4). State lives in the shared cache, so
 * every worker on every server sees the same circuit (use a shared store such as Redis when running several servers).
 *
 *  - closed: requests go through. `threshold` consecutive failures (connection failure, timeout, 5xx) open it;
 *    a 429 opens it at once, for at least ZATCA's Retry-After.
 *  - open: nothing is sent; submissions are rescheduled without using an attempt. Each time the circuit re-opens
 *    the open period doubles (`openSeconds` → … → `maxOpenSeconds`), so a long outage is probed rarely.
 *  - half-open: when the open period ends exactly ONE request (the probe) is let through; everyone else keeps
 *    waiting. A success closes the circuit, a failure re-opens it. A probe that never reports back (worker crash)
 *    is replaced after `probeSeconds`.
 */
final class CircuitBreaker
{
    /** How long the state is kept in the cache; long enough to outlive any outage the retry window covers. */
    private const STATE_TTL = 172800;

    public function __construct(
        private readonly Repository $cache,
        private readonly int $threshold = 5,
        private readonly int $openSeconds = 30,
        private readonly int $maxOpenSeconds = 600,
        private readonly int $probeSeconds = 120,
    ) {
    }

    /** @return int seconds until the caller may try again, 0 = go ahead (closed, or this caller is the probe) */
    public function retryAfter(string $name): int
    {
        $until = (int) $this->cache->get($this->key($name, 'open_until'), 0);
        if ($until === 0) {
            return 0;
        }
        $remaining = $until - time();
        if ($remaining > 0) {
            return $remaining;
        }

        // Half-open: one probe at a time.
        if ($this->cache->add($this->key($name, 'probe'), 1, max(1, $this->probeSeconds))) {
            return 0;
        }

        return max(1, min(15, $this->openSeconds));
    }

    public function isOpen(string $name): bool
    {
        return (int) $this->cache->get($this->key($name, 'open_until'), 0) > 0;
    }

    public function success(string $name): void
    {
        foreach (['failures', 'open_until', 'trips', 'probe'] as $part) {
            $this->cache->forget($this->key($name, $part));
        }
    }

    /** A connection failure / timeout / 5xx. */
    public function failure(string $name, ?int $retryAfter = null): void
    {
        $key = $this->key($name, 'failures');
        $this->cache->add($key, 0, self::STATE_TTL);
        $failures = (int) $this->cache->increment($key);

        // A failed probe re-opens at once; otherwise wait for `threshold` consecutive failures.
        if ($failures >= $this->threshold || $this->isOpen($name) || $retryAfter !== null) {
            $this->trip($name, $retryAfter);
        }
    }

    /** ZATCA said "too many requests" (429): open now, for at least its Retry-After. */
    public function throttled(string $name, ?int $retryAfter = null): void
    {
        $this->trip($name, $retryAfter ?? $this->openSeconds);
    }

    private function trip(string $name, ?int $atLeast): void
    {
        $openKey = $this->key($name, 'open_until');
        $current = (int) $this->cache->get($openKey, 0);
        $now = time();

        if ($current > $now) {
            // Already open (failures from requests that were in flight when it opened): never escalate twice,
            // only honour a longer Retry-After.
            if ($atLeast !== null && $now + $atLeast > $current) {
                $this->cache->put($openKey, $now + $atLeast, self::STATE_TTL);
            }

            return;
        }

        $tripsKey = $this->key($name, 'trips');
        $this->cache->add($tripsKey, 0, self::STATE_TTL);
        $trips = (int) $this->cache->increment($tripsKey);
        $seconds = (int) min(max(1, $this->maxOpenSeconds), max(1, $this->openSeconds) * (2 ** min(20, $trips - 1)));
        $seconds = max($seconds, (int) $atLeast);

        $this->cache->put($openKey, $now + $seconds, self::STATE_TTL);
        $this->cache->forget($this->key($name, 'probe'));
    }

    private function key(string $name, string $part): string
    {
        return "zatca:circuit:{$name}:{$part}";
    }
}
