<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Dashboard\Queries;

use CodingTriplets\Zatca\Laravel\Database\Models\B2bClearanceSubmission;
use CodingTriplets\Zatca\Laravel\Database\Models\B2cReportingSubmission;
use CodingTriplets\Zatca\Laravel\Database\Models\OutboxMessage;
use Illuminate\Support\Carbon;

/** Backlog of the outbox and of the B2B / B2C submissions (read-only, grouped counts on indexed columns). */
final class QueueStats
{
    /**
     * @return array{
     *   outbox: array{pending: int, due: int, claimed: int, dead: int, oldest_pending: ?Carbon, by_queue: array<string, int>},
     *   b2b: array{by_status: array<string, int>, oldest_open: ?Carbon},
     *   b2c: array{by_status: array<string, int>, oldest_open: ?Carbon, deadline_breached: int},
     *   high_water_mark: int
     * }
     */
    public function summary(): array
    {
        $now = now();
        $open = OutboxMessage::query()->whereNull('processed_at')->whereNull('dead_lettered_at');

        $oldest = (clone $open)->min('created_at');

        return [
            'outbox' => [
                'pending' => (clone $open)->count(),
                'due' => (clone $open)->where('available_at', '<=', $now)->where(static fn ($q) => $q->whereNull('claimed_until')->orWhere('claimed_until', '<', $now))->count(),
                'claimed' => (clone $open)->where('claimed_until', '>=', $now)->count(),
                'dead' => OutboxMessage::query()->whereNotNull('dead_lettered_at')->whereNull('processed_at')->count(),
                'oldest_pending' => $oldest !== null ? Carbon::parse($oldest) : null,
                'by_queue' => (clone $open)->selectRaw('queue, COUNT(*) as aggregate')->groupBy('queue')
                    ->pluck('aggregate', 'queue')->map(static fn ($n) => (int) $n)->all(),
            ],
            'b2b' => $this->submissions(B2bClearanceSubmission::query()),
            'b2c' => $this->submissions(B2cReportingSubmission::query()) + [
                'deadline_breached' => B2cReportingSubmission::query()->where('deadline_breached', true)->whereNull('completed_at')->count(),
            ],
            'high_water_mark' => (int) config('zatca.queues.high_water_mark', 5000),
        ];
    }

    /** @return array{by_status: array<string, int>, oldest_open: ?Carbon} */
    private function submissions($query): array
    {
        $byStatus = (clone $query)->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')
            ->pluck('aggregate', 'status')->map(static fn ($n) => (int) $n)->all();
        $oldest = (clone $query)->whereNull('completed_at')->min('created_at');

        return ['by_status' => $byStatus, 'oldest_open' => $oldest !== null ? Carbon::parse($oldest) : null];
    }
}
