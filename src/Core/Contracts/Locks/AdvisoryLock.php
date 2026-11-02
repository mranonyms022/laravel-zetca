<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Contracts\Locks;

/**
 * Cross-process advisory lock (e.g. Redis cache lock). Reduces contention only:
 * correctness of ICV/PIH allocation relies on database row locks and unique constraints,
 * because advisory leases can expire (ARCHITECTURE 13.2).
 */
interface AdvisoryLock
{
    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function withLock(string $key, int $leaseSeconds, int $waitSeconds, callable $callback): mixed;
}
