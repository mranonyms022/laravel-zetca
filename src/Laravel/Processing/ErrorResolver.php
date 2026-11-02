<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Processing;

use CodingTriplets\Zatca\Core\Contracts\Persistence\TransactionManager;
use CodingTriplets\Zatca\Laravel\Audit\AuditLogger;
use CodingTriplets\Zatca\Laravel\Database\Models\ErrorGroup;
use CodingTriplets\Zatca\Laravel\Database\Models\Invoice;
use CodingTriplets\Zatca\Laravel\Database\Models\ZatcaError;

/**
 * Marks errors as resolved by an operator (one error, or every open error of a group) and recounts the
 * open-error counters on the affected invoices and groups from the errors table. Resolving an error only
 * acknowledges it: it never changes an invoice's status or retries anything.
 */
final class ErrorResolver
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly AuditLogger $audit,
    ) {
    }

    public function resolve(ZatcaError|int $error, string $note, ?int $actorId = null): ZatcaError
    {
        $error = $error instanceof ZatcaError ? $error : ZatcaError::query()->findOrFail($error);
        $this->resolveQuery(ZatcaError::query()->whereKey($error->id), $note, $actorId, 'error.resolved', ['error_id' => (int) $error->id, 'code' => $error->code]);

        return $error->refresh();
    }

    /** @return int number of errors resolved */
    public function resolveGroup(ErrorGroup|int $group, string $note, ?int $actorId = null): int
    {
        $group = $group instanceof ErrorGroup ? $group : ErrorGroup::query()->findOrFail($group);

        return $this->resolveQuery(ZatcaError::query()->where('group_id', $group->id), $note, $actorId, 'error_group.resolved', ['group_id' => (int) $group->id, 'code' => $group->code]);
    }

    /** Recomputes open_count of the given groups (all when null) from the errors table. */
    public function recountGroups(?array $groupIds = null): void
    {
        $query = ErrorGroup::query()->withoutGlobalScopes();
        if ($groupIds !== null) {
            $query->whereIn('id', $groupIds);
        }
        foreach ($query->pluck('id') as $id) {
            ErrorGroup::query()->withoutGlobalScopes()->whereKey($id)->update([
                'open_count' => ZatcaError::query()->withoutGlobalScopes()->where('group_id', $id)->where('is_resolved', false)->count(),
            ]);
        }
    }

    /** @param \Illuminate\Database\Eloquent\Builder<ZatcaError> $query */
    private function resolveQuery($query, string $note, ?int $actorId, string $action, array $subject): int
    {
        $note = trim($note);
        if ($note === '') {
            throw new \InvalidArgumentException('A resolution note is required.');
        }
        $note = mb_substr($note, 0, 1000);

        $count = $this->transactions->run(function () use ($query, $note, $actorId): int {
            $open = (clone $query)->where('is_resolved', false)->get(['id', 'invoice_id', 'group_id']);
            if ($open->isEmpty()) {
                return 0;
            }
            ZatcaError::query()->whereIn('id', $open->pluck('id'))->update([
                'is_resolved' => true,
                'resolved_at' => now(),
                'resolved_by' => $actorId,
                'resolution_note' => $note,
            ]);

            foreach ($open->pluck('invoice_id')->filter()->unique() as $invoiceId) {
                Invoice::query()->withoutGlobalScopes()->whereKey($invoiceId)->update([
                    'unresolved_error_count' => ZatcaError::query()->withoutGlobalScopes()->where('invoice_id', $invoiceId)->where('is_resolved', false)->count(),
                ]);
            }
            $this->recountGroups($open->pluck('group_id')->filter()->unique()->values()->all());

            return $open->count();
        });

        if ($count > 0) {
            $this->audit->record($action, 'error', $subject['error_id'] ?? $subject['group_id'], $subject + ['resolved' => $count, 'note' => $note], $actorId);
        }

        return $count;
    }
}
