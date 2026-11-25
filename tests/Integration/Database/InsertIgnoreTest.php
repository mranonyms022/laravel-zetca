<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Tests\Integration\Database;

use CodingTriplets\Zatca\Laravel\Database\InsertIgnore;
use CodingTriplets\Zatca\Laravel\Database\Models\OutboxMessage;
use CodingTriplets\Zatca\Tests\Integration\IntegrationTestCase;
use Illuminate\Database\SqlServerConnection;

final class InsertIgnoreTest extends IntegrationTestCase
{
    public function test_second_insert_with_the_same_key_is_ignored(): void
    {
        $model = new OutboxMessage();
        $row = [
            'tenant_id' => '', 'aggregate_type' => 'submission', 'aggregate_id' => 1, 'event_type' => 'submit',
            'queue' => 'q', 'payload' => '{}', 'idempotency_key' => 'k-1', 'available_at' => now(), 'attempts' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ];

        self::assertSame(1, InsertIgnore::row($model->getConnection(), $model->getTable(), $row, ['idempotency_key']));
        self::assertSame(0, InsertIgnore::row($model->getConnection(), $model->getTable(), ['queue' => 'other'] + $row, ['idempotency_key']));
        self::assertSame(1, OutboxMessage::query()->withoutGlobalScopes()->count());
        self::assertSame('q', OutboxMessage::query()->withoutGlobalScopes()->value('queue'));
    }

    public function test_sql_server_uses_a_locked_not_exists_insert_instead_of_insert_or_ignore(): void
    {
        // No server needed: the statement is captured instead of executed.
        $connection = new class (static fn () => throw new \LogicException('no connection needed'), 'zatca', 'p_', ['driver' => 'sqlsrv']) extends SqlServerConnection {
            /** @var list<array{0: string, 1: array<int, mixed>}> */
            public array $statements = [];

            public function affectingStatement($query, $bindings = [])
            {
                $this->statements[] = [$query, $bindings];

                return 1;
            }
        };

        self::assertSame(1, InsertIgnore::row($connection, 'zatca_invoice_counters', ['device_id' => 7, 'environment' => 'production', 'last_icv' => 0], ['device_id', 'environment']));

        self::assertCount(1, $connection->statements);
        self::assertSame(
            'insert into [p_zatca_invoice_counters] ([device_id], [environment], [last_icv]) select ?, ?, ? '
            . 'where not exists (select 1 from [p_zatca_invoice_counters] with (updlock, holdlock) where [device_id] = ? and [environment] = ?)',
            $connection->statements[0][0],
        );
        self::assertSame([7, 'production', 0, 7, 'production'], $connection->statements[0][1]);
    }
}
