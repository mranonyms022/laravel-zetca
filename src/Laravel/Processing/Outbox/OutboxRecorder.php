<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Processing\Outbox;

use CodingTriplets\Zatca\Core\Contracts\Persistence\TransactionManager;
use CodingTriplets\Zatca\Laravel\Database\Models\OutboxMessage;

/**
 * Transactional outbox (ARCHITECTURE 14.2). Messages are written in the same transaction as the state change
 * that causes them, so a committed change can never lose its follow-up work, and a rolled-back change never
 * produces one. The idempotency key makes recording the same intent twice a no-op.
 *
 * After commit a fast path dispatches the message immediately; `zatca:outbox:relay` picks up anything the fast
 * path did not deliver (crash, backpressure, delayed messages).
 */
final class OutboxRecorder
{
    public const EVENT_PROCESS = 'process';
    public const EVENT_SUBMIT = 'submit';

    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly OutboxDispatcher $dispatcher,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function record(
        string $aggregateType,
        int $aggregateId,
        string $event,
        string $queue,
        string $idempotencyKey,
        array $payload = [],
        ?\DateTimeInterface $availableAt = null,
        ?string $tenantId = null,
    ): ?OutboxMessage {
        $key = mb_substr($idempotencyKey, 0, 191);
        $model = new OutboxMessage();
        $now = now();
        // insertOrIgnore: recording the same intent twice is a no-op, also concurrently and inside PostgreSQL
        // transactions (a caught unique violation would abort the surrounding transaction there).
        $inserted = $model->getConnection()->table($model->getTable())->insertOrIgnore([
            'tenant_id' => (string) ($tenantId ?? app(\CodingTriplets\Zatca\Core\Contracts\Tenancy\TenantResolver::class)->current() ?? ''),
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'event_type' => $event,
            'queue' => $queue,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'idempotency_key' => $key,
            'available_at' => $availableAt ?? $now,
            'attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $message = OutboxMessage::query()->withoutGlobalScopes()->where('idempotency_key', $key)->firstOrFail();
        if ($inserted === 0) {
            return $message;
        }

        if ($availableAt === null || $availableAt <= now()) {
            $id = (int) $message->id;
            $this->transactions->afterCommit(fn () => $this->dispatcher->dispatchQuietly($id));
        }

        return $message;
    }
}
