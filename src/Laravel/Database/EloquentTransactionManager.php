<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Database;

use CodingTriplets\Zatca\Core\Contracts\Persistence\TransactionManager;
use CodingTriplets\Zatca\Core\Domain\Exceptions\SequenceException;
use CodingTriplets\Zatca\Laravel\Database\Schema\Tables;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * Wraps the package connection's transactions. Retries on deadlocks (handled by Laravel's
 * transaction attempts) and on retryable SequenceException (counter CAS conflicts).
 */
final class EloquentTransactionManager implements TransactionManager
{
    public function __construct(private readonly ConnectionResolverInterface $db)
    {
    }

    public function run(callable $work, int $attempts = 1): mixed
    {
        // A unit of work nested in an outer transaction cannot be safely re-run on its own:
        // the outer transaction owns the retry.
        $attempts = $this->connection()->transactionLevel() > 0 ? 1 : max(1, $attempts);

        for ($try = 1; ; $try++) {
            try {
                return $this->connection()->transaction(static fn () => $work(), 1);
            } catch (SequenceException $e) {
                if (! $e->isRetryable() || $try >= $attempts) {
                    throw $e;
                }
                usleep(random_int(5_000, 25_000) * $try); // jittered backoff before re-running the unit of work
            } catch (\Throwable $e) {
                if ($try >= $attempts || ! $this->isDeadlock($e)) {
                    throw $e;
                }
                usleep(random_int(5_000, 25_000) * $try);
            }
        }
    }

    public function afterCommit(callable $callback): void
    {
        $connection = $this->connection();

        // DB::afterCommit runs immediately when no transaction is open (Laravel 8+).
        if (method_exists($connection, 'afterCommit')) {
            $connection->afterCommit($callback);

            return;
        }

        $callback();
    }

    private function connection(): \Illuminate\Database\ConnectionInterface
    {
        return $this->db->connection(Tables::connection());
    }

    private function isDeadlock(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        foreach (['deadlock', 'lock wait timeout', 'serialization failure', 'database is locked', 'could not serialize'] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }
}
