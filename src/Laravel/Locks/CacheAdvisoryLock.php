<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Locks;

use CodingTriplets\Zatca\Core\Contracts\Locks\AdvisoryLock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;

/**
 * Advisory lock on a lock-capable cache store (redis, memcached, dynamodb, database, file).
 * Falls back to running without the advisory lock when the store cannot lock: correctness is
 * guaranteed by database row locks and unique constraints, not by this lock.
 */
final class CacheAdvisoryLock implements AdvisoryLock
{
    public function __construct(private readonly Repository $cache)
    {
    }

    public function withLock(string $key, int $leaseSeconds, int $waitSeconds, callable $callback): mixed
    {
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            return $callback();
        }

        return $store->lock('zatca:' . $key, $leaseSeconds)->block($waitSeconds, $callback);
    }
}
