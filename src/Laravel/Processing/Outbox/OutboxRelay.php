<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Processing\Outbox;

use CodingTriplets\Zatca\Laravel\Database\Models\OutboxMessage;

/**
 * Safety net and scheduler for the outbox: dispatches due messages that the after-commit fast path did not
 * deliver (process crash, backpressure, delayed retries, expired leases). Earliest `available_at` first, so B2C
 * reporting work is drained in deadline order.
 */
final class OutboxRelay
{
    public function __construct(private readonly OutboxDispatcher $dispatcher)
    {
    }

    /** @return array{dispatched: int, skipped: int} */
    public function relay(int $limit = 500): array
    {
        $now = now();
        $ids = OutboxMessage::query()->withoutGlobalScopes()
            ->whereNull('processed_at')
            ->whereNull('dead_lettered_at')
            ->where('available_at', '<=', $now)
            ->where(static fn ($q) => $q->whereNull('claimed_until')->orWhere('claimed_until', '<', $now))
            ->orderBy('available_at')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'queue']);

        $dispatched = 0;
        $skipped = 0;
        foreach ($ids as $row) {
            if ($this->dispatcher->saturated((string) $row->queue)) {
                $skipped++;

                continue;
            }
            try {
                $this->dispatcher->dispatch((int) $row->id) ? $dispatched++ : $skipped++;
            } catch (\Throwable) {
                $skipped++;
            }
        }

        return ['dispatched' => $dispatched, 'skipped' => $skipped];
    }

    /** Deletes processed messages older than the retention period. Dead letters are kept. */
    public function prune(int $days): int
    {
        $deleted = 0;
        do {
            $ids = OutboxMessage::query()->withoutGlobalScopes()
                ->whereNotNull('processed_at')
                ->where('processed_at', '<', now()->subDays($days))
                ->limit(1000)->pluck('id');
            if ($ids->isNotEmpty()) {
                $deleted += OutboxMessage::query()->withoutGlobalScopes()->whereIn('id', $ids)->delete();
            }
        } while ($ids->count() === 1000);

        return $deleted;
    }
}
