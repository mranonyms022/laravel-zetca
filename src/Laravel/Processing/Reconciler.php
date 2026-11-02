<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Processing;

use CodingTriplets\Zatca\Core\Domain\Enums\ProcessingStatus;
use CodingTriplets\Zatca\Core\Domain\Enums\Workflow;
use CodingTriplets\Zatca\Laravel\Database\Models\B2bClearanceSubmission;
use CodingTriplets\Zatca\Laravel\Database\Models\B2cReportingSubmission;
use CodingTriplets\Zatca\Laravel\Database\Models\Invoice;
use CodingTriplets\Zatca\Laravel\Database\Models\OutboxMessage;
use CodingTriplets\Zatca\Laravel\Invoices\QueueNames;
use CodingTriplets\Zatca\Laravel\Processing\Outbox\OutboxRecorder;

/**
 * Safety net for work that has no pending outbox message (ARCHITECTURE 14):
 *  - submissions still `pending` / `unknown`, or `in_flight` with an expired lease (worker killed mid-request);
 *  - B2B invoices READY_FOR_PROCESSING that were received for queued generation.
 * A new outbox message is recorded for each; the submission itself is never changed here, and an `unknown`
 * outcome is resolved by resending the identical document (ZATCA has no status lookup ⚠).
 */
final class Reconciler
{
    public function __construct(private readonly OutboxRecorder $outbox)
    {
    }

    /** @return array{submissions: int, generation: int} */
    public function reconcile(int $olderThanSeconds = 300, int $limit = 1000): array
    {
        $cutoff = now()->subSeconds($olderThanSeconds);
        $counts = ['submissions' => 0, 'generation' => 0];

        foreach ([[Workflow::B2B, B2bClearanceSubmission::class], [Workflow::B2C, B2cReportingSubmission::class]] as [$workflow, $model]) {
            $stuck = $model::query()
                ->where('updated_at', '<', $cutoff)
                ->where(static fn ($q) => $q->whereIn('status', ['pending', 'unknown'])
                    ->orWhere(static fn ($q) => $q->where('status', 'in_flight')->where('claimed_until', '<', now())))
                ->orderBy('id')->limit($limit)->get(['id', 'issuance_id', 'invoice_id', 'attempts']);

            foreach ($stuck as $submission) {
                if ($this->hasPending('submission', (int) $submission->id)) {
                    continue;
                }
                $invoice = Invoice::query()->withoutGlobalScopes()->find($submission->invoice_id);
                if ($invoice === null || (int) $invoice->current_issuance_id !== (int) $submission->issuance_id) {
                    continue;
                }
                $this->outbox->record('submission', (int) $submission->id, OutboxRecorder::EVENT_SUBMIT, QueueNames::submission($workflow),
                    "reconcile:{$workflow->value}:{$submission->id}:{$submission->attempts}:" . now()->format('YmdH'), ['workflow' => $workflow->value], tenantId: (string) $invoice->tenant_id);
                $counts['submissions']++;
            }
        }

        $ready = Invoice::query()->withoutGlobalScopes()
            ->where('workflow', Workflow::B2B)
            ->where('processing_status', ProcessingStatus::ReadyForProcessing)
            ->whereNull('current_issuance_id')
            ->where('requires_approval', false)
            ->where('status_changed_at', '<', $cutoff)
            ->orderBy('id')->limit($limit)->get(['id', 'public_id', 'tenant_id']);
        foreach ($ready as $invoice) {
            if ($this->hasPending('invoice', (int) $invoice->id)) {
                continue;
            }
            $this->outbox->record('invoice', (int) $invoice->id, OutboxRecorder::EVENT_PROCESS, QueueNames::generation(Workflow::B2B),
                "reconcile:process:{$invoice->public_id}:" . now()->format('YmdH'), tenantId: (string) $invoice->tenant_id);
            $counts['generation']++;
        }

        return $counts;
    }

    private function hasPending(string $aggregate, int $id): bool
    {
        return OutboxMessage::query()->where('aggregate_type', $aggregate)->where('aggregate_id', $id)
            ->whereNull('processed_at')->whereNull('dead_lettered_at')->exists();
    }
}
