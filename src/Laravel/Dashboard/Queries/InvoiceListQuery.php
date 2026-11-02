<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Dashboard\Queries;

use CodingTriplets\Zatca\Core\Domain\Enums\ProcessingStatus as P;
use CodingTriplets\Zatca\Core\Domain\Enums\Workflow;
use CodingTriplets\Zatca\Laravel\Database\Models\Invoice;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * B2B / B2C invoice lists: tab (status group), search and filters, newest first, simple pagination
 * (no COUNT(*) over the whole table). Tenant scoping comes from the model's global scope.
 */
final class InvoiceListQuery
{
    public const PER_PAGE = 50;

    /** @return array<string, array{label: string, statuses: list<P>|null}> */
    public static function tabs(Workflow $workflow): array
    {
        $accepted = $workflow === Workflow::B2B ? [P::Cleared] : [P::Reported];

        return [
            'all' => ['label' => 'All', 'statuses' => null],
            'pending' => ['label' => $workflow === Workflow::B2B ? 'Pending clearance' : 'Pending reporting', 'statuses' => [
                P::Received, P::Validating, P::ReadyForProcessing, P::XmlGenerating, P::XmlGenerated, P::Signing,
                P::Signed, P::Queued, P::Submitting, P::Submitted, P::RetryPending, P::OutcomeUnknown,
            ]],
            'accepted' => ['label' => $workflow === Workflow::B2B ? 'Cleared' : 'Reported', 'statuses' => array_merge($accepted, [P::Completed])],
            'rejected' => ['label' => 'Rejected', 'statuses' => [P::Rejected]],
            'failed' => ['label' => 'Failed', 'statuses' => [P::Failed, P::ValidationFailed]],
            'manual' => ['label' => 'Manual review', 'statuses' => [P::ManualReview]],
            'closed' => ['label' => 'Cancelled / abandoned', 'statuses' => [P::Cancelled, P::Abandoned]],
        ];
    }

    /**
     * @param array{tab?: ?string, q?: ?string, device?: ?string, type?: ?string, from?: ?string, to?: ?string} $filters
     */
    public function paginate(Workflow $workflow, array $filters): Paginator
    {
        return $this->query($workflow, $filters)
            ->with(['device:id,name', 'currentIssuance:id,icv'])
            ->orderByDesc('id')
            ->simplePaginate(self::PER_PAGE)
            ->withQueryString();
    }

    /** @param array<string, mixed> $filters */
    public function query(Workflow $workflow, array $filters): Builder
    {
        $query = Invoice::query()->where('workflow', $workflow);

        $tab = self::tabs($workflow)[(string) ($filters['tab'] ?? 'all')] ?? null;
        if ($tab !== null && $tab['statuses'] !== null) {
            $query->whereIn('processing_status', array_map(static fn (P $s) => $s->value, $tab['statuses']));
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $q = mb_substr($q, 0, 100);
            $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $query->where(static function (Builder $w) use ($q, $like): void {
                $w->where('invoice_number', 'like', $like)
                    ->orWhere('external_invoice_id', 'like', $like)
                    ->orWhere('public_id', $q)
                    ->when(\Illuminate\Support\Str::isUuid($q), static fn ($u) => $u->orWhere('current_uuid', $q))   // uuid column (PostgreSQL)
                    ->orWhere('buyer_vat_number', $q);
            });
        }

        if (($device = (string) ($filters['device'] ?? '')) !== '' && ctype_digit($device)) {
            $query->where('device_id', (int) $device);
        }
        if (in_array($type = (string) ($filters['type'] ?? ''), ['388', '381', '383'], true)) {
            $query->where('document_type', $type);
        }
        if (($from = $this->date($filters['from'] ?? null)) !== null) {
            $query->whereDate('invoice_date', '>=', $from);
        }
        if (($to = $this->date($filters['to'] ?? null)) !== null) {
            $query->whereDate('invoice_date', '<=', $to);
        }

        return $query;
    }

    private function date(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }
}
