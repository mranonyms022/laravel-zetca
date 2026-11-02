<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Http\Middleware;

use CodingTriplets\Zatca\Core\Domain\Enums\Workflow;
use CodingTriplets\Zatca\Laravel\Invoices\QueueNames;
use CodingTriplets\Zatca\Laravel\Processing\Outbox\OutboxDispatcher;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Load shedding (ARCHITECTURE 15.6): answer fast with 429/503 + Retry-After instead of letting requests pile up
 * until the web server answers 502.
 *  - more than `zatca.performance.max_in_flight_issuance` concurrent issuance requests (per cache) → 429;
 *  - submission queue above `zatca.queues.high_water_mark` → 503.
 */
final class ShedLoad
{
    public function __construct(private readonly Repository $cache, private readonly OutboxDispatcher $dispatcher)
    {
    }

    public function handle(Request $request, \Closure $next): mixed
    {
        if (! $request->isMethod('POST')) {
            return $next($request);
        }

        $workflow = str_contains($request->path(), 'b2b/') ? Workflow::B2B : Workflow::B2C;
        if ($this->dispatcher->saturated(QueueNames::submission($workflow))) {
            return $this->refuse(503, 'queue saturated, retry later', 5);
        }

        $key = 'zatca:inflight:issuance';
        $limit = (int) config('zatca.performance.max_in_flight_issuance', 200);
        $this->cache->add($key, 0, 60);
        $current = (int) $this->cache->increment($key);
        try {
            if ($limit > 0 && $current > $limit) {
                return $this->refuse(429, 'too many concurrent requests, retry later', 1);
            }

            return $next($request);
        } finally {
            $this->cache->decrement($key);
        }
    }

    private function refuse(int $status, string $message, int $retryAfter): JsonResponse
    {
        return new JsonResponse(['status' => false, 'message' => $message], $status, ['Retry-After' => (string) $retryAfter]);
    }
}
