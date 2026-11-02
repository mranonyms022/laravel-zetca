<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Processing\Submission;

use Illuminate\Contracts\Cache\Repository;

/**
 * Per-environment circuit breaker for ZATCA transport failures (ARCHITECTURE 15.4). After
 * `zatca.http.circuit.failures` consecutive failures the circuit opens for `open_seconds`; afterwards one
 * request is let through (half-open) and its result closes or re-opens the circuit. While open, submissions are
 * rescheduled instead of hammering an unavailable endpoint. State lives in the shared cache (all workers).
 */
final class CircuitBreaker
{
    public function __construct(
        private readonly Repository $cache,
        private readonly int $threshold = 5,
        private readonly int $openSeconds = 60,
    ) {
    }

    /** @return int seconds until the circuit may be tried again, 0 = closed (or half-open probe allowed) */
    public function retryAfter(string $name): int
    {
        $until = (int) $this->cache->get($this->key($name, 'open_until'), 0);
        $remaining = $until - time();
        if ($remaining <= 0) {
            return 0;
        }

        return $remaining;
    }

    public function success(string $name): void
    {
        $this->cache->forget($this->key($name, 'failures'));
        $this->cache->forget($this->key($name, 'open_until'));
    }

    public function failure(string $name): void
    {
        $key = $this->key($name, 'failures');
        $this->cache->add($key, 0, 3600);
        $failures = (int) $this->cache->increment($key);
        if ($failures >= $this->threshold) {
            $this->cache->put($this->key($name, 'open_until'), time() + $this->openSeconds, $this->openSeconds + 60);
        }
    }

    private function key(string $name, string $part): string
    {
        return "zatca:circuit:{$name}:{$part}";
    }
}
