<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Database;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Database\SqlServerConnection;

/**
 * "Insert this row unless a row with the same unique key exists", on every supported database.
 *
 * MySQL / MariaDB / PostgreSQL / SQLite use Laravel's insertOrIgnore (INSERT IGNORE / ON CONFLICT DO NOTHING / INSERT OR
 * IGNORE). Laravel's SQL Server grammar has no insertOrIgnore and throws, so SQL Server gets
 *
 *   INSERT INTO t (...) SELECT ?, ... WHERE NOT EXISTS (SELECT 1 FROM t WITH (UPDLOCK, HOLDLOCK) WHERE key = ? ...)
 *
 * UPDLOCK + HOLDLOCK takes a key-range lock on the unique key, so two concurrent callers cannot both insert: the second
 * waits, then sees the row and inserts nothing. As a last line of defence a duplicate-key error (2627 / 2601) is also
 * treated as "ignored": on SQL Server it only rolls back the statement, not the surrounding transaction.
 */
final class InsertIgnore
{
    /**
     * @param array<string, mixed> $values   one row, column => value
     * @param list<string>         $uniqueBy the columns of the unique index that decides "already exists"
     * @return int rows inserted (0 or 1)
     */
    public static function row(ConnectionInterface $connection, string $table, array $values, array $uniqueBy): int
    {
        if (! $connection instanceof SqlServerConnection && $connection->getDriverName() !== 'sqlsrv') {
            return $connection->table($table)->insertOrIgnore($values);
        }

        $grammar = $connection->getQueryGrammar();
        $wrapped = $grammar->wrapTable($table);
        $where = implode(' and ', array_map(static fn (string $column): string => $grammar->wrap($column) . ' = ?', $uniqueBy));
        $sql = "insert into {$wrapped} (" . $grammar->columnize(array_keys($values)) . ') select ' . $grammar->parameterize($values)
            . " where not exists (select 1 from {$wrapped} with (updlock, holdlock) where {$where})";
        $bindings = [...array_values($values), ...array_map(static fn (string $column): mixed => $values[$column], $uniqueBy)];

        try {
            return $connection->affectingStatement($sql, $bindings);
        } catch (QueryException $e) {
            if (in_array((int) ($e->errorInfo[1] ?? 0), [2601, 2627], true)) {
                return 0;
            }

            throw $e;
        }
    }
}
