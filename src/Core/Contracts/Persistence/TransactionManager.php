<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Contracts\Persistence;

/**
 * Runs a unit of work atomically. Implementations must never be used to wrap HTTP calls
 * (ARCHITECTURE 14.1: claim (tx) -> I/O -> record (tx)).
 */
interface TransactionManager
{
    /**
     * @template T
     * @param callable(): T $work
     * @param int $attempts re-run on deadlock / retryable SequenceException
     * @return T
     */
    public function run(callable $work, int $attempts = 1): mixed;

    /** Registers a callback executed only after the outermost transaction commits. */
    public function afterCommit(callable $callback): void;
}
