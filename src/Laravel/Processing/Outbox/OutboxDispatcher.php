<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Processing\Outbox;

use CodingTriplets\Zatca\Laravel\Database\Models\OutboxMessage;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Moves outbox messages onto Laravel queues.
 *
 * A message is claimed with a lease (conditional UPDATE, so only one dispatcher wins) before its job is pushed.
 * If the job never runs (lost queue message, crashed worker), the lease expires and the relay dispatches it again;
 * handlers are idempotent, so a duplicate delivery is harmless.
 *
 * Backpressure: while a target queue holds more than `zatca.queues.high_water_mark` jobs, messages stay in the
 * outbox (the reservoir) instead of flooding the queue.
 */
final class OutboxDispatcher
{
    /** @var array<string, array{at: float, size: int}> */
    private array $sizes = [];

    public function __construct(
        private readonly QueueFactory $queues,
        private readonly ?string $connection = null,
        private readonly int $highWaterMark = 5000,
        private readonly int $leaseSeconds = 600,
    ) {
    }

    /** Fast path after commit: never throws (the relay is the safety net). */
    public function dispatchQuietly(int $messageId): void
    {
        // With the "sync" queue driver a pushed job runs inside the current request (a ZATCA call in the
        // response path). Leave such messages to the relay instead.
        if (config('queue.connections.' . ($this->connection ?? config('queue.default')) . '.driver') === 'sync' && ! config('zatca.outbox.dispatch_on_sync_driver', false)) {
            return;
        }

        try {
            $this->dispatch($messageId);
        } catch (\Throwable $e) {
            Log::warning('[zatca] outbox fast-path dispatch failed; the relay will retry.', ['outbox_id' => $messageId, 'error' => $e->getMessage()]);
        }
    }

    /** @return bool true when the job was pushed */
    public function dispatch(int $messageId): bool
    {
        $message = OutboxMessage::query()->withoutGlobalScopes()->find($messageId);
        if ($message === null || $message->processed_at !== null || $message->dead_lettered_at !== null) {
            return false;
        }
        if ($this->saturated((string) $message->queue)) {
            return false;
        }

        $token = (string) Str::uuid();
        $now = now();
        $claimed = OutboxMessage::query()->withoutGlobalScopes()
            ->whereKey($messageId)
            ->whereNull('processed_at')
            ->whereNull('dead_lettered_at')
            ->where('available_at', '<=', $now)
            ->where(static fn ($q) => $q->whereNull('claimed_until')->orWhere('claimed_until', '<', $now))
            ->update(['claimed_by' => $token, 'claimed_until' => $now->copy()->addSeconds($this->leaseSeconds), 'updated_at' => $now]);

        if ($claimed !== 1) {
            return false;
        }

        try {
            $this->queues->connection($this->connection)->pushOn((string) $message->queue, new OutboxJob($messageId, $token));
            $this->sizes[(string) $message->queue]['size'] = ($this->sizes[(string) $message->queue]['size'] ?? 0) + 1;
        } catch (\Throwable $e) {
            OutboxMessage::query()->withoutGlobalScopes()->whereKey($messageId)->where('claimed_by', $token)
                ->update(['claimed_by' => null, 'claimed_until' => null, 'last_error' => mb_substr('dispatch: ' . $e->getMessage(), 0, 2000)]);

            throw $e;
        }

        return true;
    }

    public function saturated(string $queue): bool
    {
        if ($this->highWaterMark <= 0) {
            return false;
        }

        $cached = $this->sizes[$queue] ?? null;
        if ($cached === null || microtime(true) - $cached['at'] > 1.0) {
            try {
                $size = $this->queues->connection($this->connection)->size($queue);
            } catch (\Throwable) {
                $size = 0; // drivers without size support: no backpressure
            }
            $this->sizes[$queue] = $cached = ['at' => microtime(true), 'size' => (int) $size];
        }

        return $cached['size'] >= $this->highWaterMark;
    }
}
