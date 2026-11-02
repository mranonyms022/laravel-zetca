<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Invoices;

use CodingTriplets\Zatca\Core\Contracts\Persistence\TransactionManager;
use CodingTriplets\Zatca\Core\Domain\Enums\DocumentType;
use CodingTriplets\Zatca\Core\Domain\Enums\IssuanceReason;
use CodingTriplets\Zatca\Core\Domain\Enums\IssuanceStatus;
use CodingTriplets\Zatca\Core\Domain\Enums\ProcessingStatus;
use CodingTriplets\Zatca\Core\Domain\Enums\SubmissionStatus;
use CodingTriplets\Zatca\Core\Domain\Enums\ValidationStatus;
use CodingTriplets\Zatca\Core\Domain\Enums\VersionKind;
use CodingTriplets\Zatca\Core\Domain\Enums\Workflow;
use CodingTriplets\Zatca\Core\Invoice\Shared\Calculation\InvoiceTotals;
use CodingTriplets\Zatca\Core\Invoice\Shared\Validation\InvoicePayloadMapper;
use CodingTriplets\Zatca\Core\Invoice\Shared\Validation\InvoiceValidationException;
use CodingTriplets\Zatca\Core\Invoice\Shared\Validation\ValidationResult;
use CodingTriplets\Zatca\Laravel\Database\Models\B2bClearanceSubmission;
use CodingTriplets\Zatca\Laravel\Database\Models\B2cReportingSubmission;
use CodingTriplets\Zatca\Laravel\Database\Models\Device;
use CodingTriplets\Zatca\Laravel\Database\Models\Invoice;
use CodingTriplets\Zatca\Laravel\Database\Models\InvoiceIssuance;
use CodingTriplets\Zatca\Laravel\Database\Models\InvoiceVersion;
use CodingTriplets\Zatca\Laravel\Database\Models\ZatcaError;
use CodingTriplets\Zatca\Laravel\Processing\Outbox\OutboxRecorder;
use CodingTriplets\Zatca\Laravel\Processing\ProcessingLogger;
use CodingTriplets\Zatca\Laravel\Processing\Submission\SubmissionRunner;

/**
 * Human operations on an existing invoice (ARCHITECTURE 12). Every one is explicit, logged with the actor and
 * guarded by the invoice's state. Nothing here runs automatically.
 *
 *  A  retry()            resend the byte-identical signed document (transient failure, unknown outcome, fixed 401)
 *  B  correct()          new JSON version; a rejected document is replaced by a new issuance (new UUID, next ICV)
 *     reissueUnchanged() new issuance from the same JSON (rejection caused by the package or a certificate)
 *     approve()          release a correction held for approval (four-eyes)
 *  C  creditNote() / debitNote()  the only way to change an accepted (REPORTED / CLEARED) document
 */
final class InvoiceOperations
{
    private const RETRYABLE = [
        ProcessingStatus::Queued, ProcessingStatus::RetryPending, ProcessingStatus::Failed,
        ProcessingStatus::OutcomeUnknown, ProcessingStatus::ManualReview,
    ];

    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly InvoiceProcessor $processor,
        private readonly ChainService $chain,
        private readonly InvoiceGenerators $generators,
        private readonly PayloadPreparer $preparer,
        private readonly RejectionPolicies $policies,
        private readonly ProcessingLogger $logger,
        private readonly OutboxRecorder $outbox,
        private readonly SubmissionRunner $runner,
    ) {
    }

    // ------------------------------------------------------------------ A. Retry submission

    /**
     * Resend the current signed document unchanged. UUID, ICV, PIH, hash, signature and QR stay the same.
     *
     * @param bool $now submit inside this call instead of queuing it
     * @return string the submission status
     */
    public function retry(Invoice|string $invoice, ?int $actorId = null, bool $now = false): string
    {
        $invoice = $this->find($invoice);
        $issuance = $this->currentIssuance($invoice);
        if ($issuance === null) {
            throw OperationNotAllowed::because($invoice, 'it has no signed document yet (correct or reprocess it instead)');
        }
        if ($issuance->status !== IssuanceStatus::Issued) {
            throw OperationNotAllowed::because($invoice, "its document is {$issuance->status->value}; only a document without a final ZATCA result can be resent");
        }
        if (! in_array($invoice->processing_status, self::RETRYABLE, true)) {
            throw OperationNotAllowed::because($invoice, "it is {$invoice->processing_status->value}");
        }

        $workflow = $invoice->workflow;
        $model = $this->submissionModel($workflow);

        $submissionId = $this->transactions->run(function () use ($invoice, $issuance, $model, $workflow, $actorId, $now): int {
            $submission = $model::query()->where('issuance_id', $issuance->id)->lockForUpdate()->firstOrFail();
            if ($submission->status === 'in_flight' && $submission->claimed_until !== null && $submission->claimed_until->isFuture()) {
                throw OperationNotAllowed::because($invoice, 'a submission is in progress');
            }
            $submission->forceFill(['status' => $submission->status === 'unknown' ? 'unknown' : 'pending', 'claimed_until' => null, 'completed_at' => null, 'requested_by' => $actorId])->save();

            $locked = Invoice::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($invoice->id);
            $this->logger->transition($locked, ['processing_status' => ProcessingStatus::Queued, 'submission_status' => SubmissionStatus::Pending], 'manual_retry', 'Resend of the identical signed document', (int) $issuance->id, $actorId);
            $locked->next_retry_at = null;
            $locked->save();

            if (! $now) {
                $this->outbox->record('submission', (int) $submission->id, OutboxRecorder::EVENT_SUBMIT, QueueNames::submission($workflow),
                    "manual:{$issuance->uuid}:" . ((int) $submission->attempts + 1), ['workflow' => $workflow->value], tenantId: (string) $locked->tenant_id);
            }

            return (int) $submission->id;
        });

        return $now ? $this->runner->run($workflow, $submissionId, manual: true) : 'pending';
    }

    // ------------------------------------------------------------------ B. Correct & reprocess

    /**
     * Save a corrected JSON version (append-only; the original stays) and, unless approval is required,
     * reprocess it. For a rejected document this creates issuance n+1 with a new UUID, the next ICV and the
     * chain-head PIH. The rejected document stays in the chain.
     *
     * @param array<string, mixed> $payload the full corrected invoice JSON
     * @throws InvoiceValidationException nothing is saved when the correction is invalid
     */
    public function correct(Invoice|string $invoice, array $payload, string $reason, ?int $actorId = null): IssueResult
    {
        $invoice = $this->find($invoice);
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('A reason is required for every correction.');
        }

        $issuance = $this->currentIssuance($invoice);
        $policy = $this->policies->for($invoice->workflow);
        $this->assertCorrectable($invoice, $issuance);
        if ($issuance !== null && $policy->requiresCreditNote()) {
            throw OperationNotAllowed::because($invoice, 'the configured policy requires a credit note for rejected documents');
        }

        $type = strtolower((string) ($payload['type'] ?? 'invoice'));
        $expected = ['388' => 'invoice', '381' => 'credit_note', '383' => 'debit_note'][$invoice->document_type->value];
        if (! in_array($type, [$expected, $invoice->document_type->value], true)) {
            throw OperationNotAllowed::because($invoice, 'the document type cannot change in a correction');
        }

        // Invoice number: kept for a rejected document unless the policy says otherwise ⚠.
        $invoiceNumber = (string) $invoice->invoice_number;
        $requested = trim((string) ($payload['invoice_number'] ?? ''));
        if ($issuance === null || ! $policy->keepInvoiceNumber()) {
            $invoiceNumber = $requested !== '' ? mb_substr($requested, 0, 191) : $invoiceNumber;
        }
        if ($issuance !== null && ! $policy->keepInvoiceNumber() && $invoiceNumber === (string) $invoice->invoice_number) {
            throw new \InvalidArgumentException('The policy requires a new invoice_number for a reissued rejected document.');
        }

        $device = Device::query()->withoutGlobalScopes()->findOrFail($invoice->device_id);
        $prepared = $this->preparer->prepare($payload, $invoice->workflow, $device, $invoiceNumber);
        $validation = $this->generators->for($invoice->workflow)->validate($prepared);
        if (! $validation->isValid()) {
            throw InvoiceValidationException::fromResult($validation);
        }
        $document = (new InvoicePayloadMapper())->map($prepared, new ValidationResult(), $invoice->workflow);
        $totals = InvoiceTotals::calculate($document);
        $fourEyes = (bool) config('zatca.correction.four_eyes', false);

        $this->transactions->run(function () use ($invoice, $payload, $reason, $actorId, $validation, $document, $totals, $invoiceNumber, $issuance, $fourEyes): void {
            $locked = Invoice::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($invoice->id);
            if ($locked->current_issuance_id !== $invoice->current_issuance_id || $locked->processing_status !== $invoice->processing_status) {
                throw OperationNotAllowed::because($locked, 'it changed while the correction was prepared; reload and try again');
            }

            /** @var InvoiceVersion $current */
            $current = InvoiceVersion::query()->findOrFail($locked->current_version_id);
            $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $next = (int) InvoiceVersion::query()->where('invoice_id', $locked->id)->max('version') + 1;
            $version = InvoiceVersion::query()->create([
                'invoice_id' => $locked->id,
                'version' => $next,
                'previous_version_id' => $current->id,
                'kind' => VersionKind::Correction,
                'payload' => $payload,
                'payload_sha256' => $hash,
                'changed_fields' => self::changedFields((array) $current->payload, $payload),
                'reason' => $reason,
                'validation_result' => ['valid' => true, 'errors' => [], 'warnings' => $validation->warnings()],
                'created_by' => $actorId,
            ]);

            // Errors of the pre-issuance (validation) stage are resolved by a valid correction.
            if ($issuance === null) {
                ZatcaError::query()->withoutGlobalScopes()->where('invoice_id', $locked->id)->whereNull('issuance_id')->where('is_resolved', false)
                    ->update(['is_resolved' => true, 'resolved_at' => now(), 'resolved_by' => $actorId, 'resolution_note' => "Corrected in version {$next}"]);
                $locked->unresolved_error_count = ZatcaError::query()->withoutGlobalScopes()->where('invoice_id', $locked->id)->where('is_resolved', false)->count();
            }

            $locked->forceFill([
                'current_version_id' => $version->id,
                'invoice_number' => $invoiceNumber,
                'total_excl_vat' => $totals->taxExclusive->format(2),
                'vat_total' => $totals->taxTotal->format(2),
                'total_incl_vat' => $totals->taxInclusive->format(2),
                'buyer_vat_number' => $document->buyer?->vatNumber !== null ? mb_substr($document->buyer->vatNumber, 0, 15) : null,
                'subtype_flags' => $document->subtypeFlags,
                'requires_approval' => $fourEyes,
                'approved_by' => null,
                'approved_at' => null,
            ]);
            $this->logger->log($locked, 'version', 'corrected', null, (string) $next, $reason, ['version' => $next], $issuance?->id, $actorId);
            $this->logger->transition($locked, [
                'validation_status' => $validation->warnings() === [] ? ValidationStatus::Valid : ValidationStatus::ValidWithWarnings,
                'processing_status' => $fourEyes ? ProcessingStatus::ManualReview : ProcessingStatus::ReadyForProcessing,
            ], $fourEyes ? 'awaiting_approval' : 'correction_ready', $reason, $issuance?->id, $actorId);
            $locked->save();
        });

        return $fourEyes ? IssueResult::fromInvoice($invoice, false) : $this->reprocess($invoice, $actorId);
    }

    /** New issuance from the unchanged JSON, for a rejection caused by the package or a certificate. */
    public function reissueUnchanged(Invoice|string $invoice, string $reason, ?int $actorId = null): IssueResult
    {
        $invoice = $this->find($invoice);
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('A reason is required.');
        }
        $issuance = $this->currentIssuance($invoice);
        if ($issuance === null || $issuance->status !== IssuanceStatus::Rejected) {
            throw OperationNotAllowed::because($invoice, 'only a rejected document can be reissued');
        }
        if ($this->policies->for($invoice->workflow)->requiresCreditNote()) {
            throw OperationNotAllowed::because($invoice, 'the configured policy requires a credit note for rejected documents');
        }

        $this->transactions->run(function () use ($invoice, $reason, $actorId, $issuance): void {
            $locked = Invoice::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($invoice->id);
            if ($locked->processing_status !== ProcessingStatus::Rejected) {
                throw OperationNotAllowed::because($locked, "it is {$locked->processing_status->value}");
            }
            $this->logger->transition($locked, ['processing_status' => ProcessingStatus::ReadyForProcessing], 'reissue_requested', $reason, (int) $issuance->id, $actorId);
            $locked->save();
        });

        return $this->issueAgain($invoice, IssuanceReason::ReissueUnchanged, $actorId);
    }

    /** Releases a correction held for approval. With four-eyes on, the approver must not be the corrector. */
    public function approve(Invoice|string $invoice, int $approverId): IssueResult
    {
        $invoice = $this->find($invoice);
        if (! $invoice->requires_approval || $invoice->processing_status !== ProcessingStatus::ManualReview) {
            throw OperationNotAllowed::because($invoice, 'it is not waiting for approval');
        }
        $corrector = InvoiceVersion::query()->whereKey($invoice->current_version_id)->value('created_by');
        if (config('zatca.correction.four_eyes', false) && $corrector !== null && (int) $corrector === $approverId) {
            throw OperationNotAllowed::because($invoice, 'the approver must be a different person than the corrector');
        }

        $this->transactions->run(function () use ($invoice, $approverId): void {
            $locked = Invoice::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($invoice->id);
            $locked->forceFill(['requires_approval' => false, 'approved_by' => $approverId, 'approved_at' => now()]);
            $this->logger->transition($locked, ['processing_status' => ProcessingStatus::ReadyForProcessing], 'approved', null, $locked->current_issuance_id, $approverId);
            $locked->save();
        });

        return $this->reprocess($invoice, $approverId);
    }

    // ------------------------------------------------------------------ C. Credit / debit note

    /**
     * Cancel or reduce an accepted invoice with a credit note (381) in the same workflow.
     *
     * @param array<string, mixed> $payload note lines (and invoice_number); buyer/supply date default to the original's
     * @param array{device?: int|string, external_id?: string} $options
     */
    public function creditNote(Invoice|string $original, array $payload, string $reason, ?int $actorId = null, array $options = []): IssueResult
    {
        return $this->note('credit_note', $original, $payload, $reason, $actorId, $options);
    }

    /** Increase an accepted invoice with a debit note (383) in the same workflow. */
    public function debitNote(Invoice|string $original, array $payload, string $reason, ?int $actorId = null, array $options = []): IssueResult
    {
        return $this->note('debit_note', $original, $payload, $reason, $actorId, $options);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     */
    private function note(string $type, Invoice|string $original, array $payload, string $reason, ?int $actorId, array $options): IssueResult
    {
        $original = $this->find($original);
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('A reason is required for a credit or debit note (KSA-10).');
        }
        if ($original->document_type !== DocumentType::Invoice) {
            throw OperationNotAllowed::because($original, 'notes can only reference an invoice');
        }
        $issuance = $this->currentIssuance($original);
        if ($issuance === null || ! in_array($issuance->status, [IssuanceStatus::Accepted, IssuanceStatus::AcceptedWithWarnings], true)) {
            throw OperationNotAllowed::because($original, 'a credit or debit note needs an accepted (REPORTED / CLEARED) invoice; correct a rejected one instead');
        }

        $base = (array) InvoiceVersion::query()->whereKey($original->current_version_id)->value('payload');
        $base = is_array($base) ? $base : [];
        $payload['type'] = $type;
        $payload['billing_reference'] = (string) $original->invoice_number;
        $payload['reason'] = trim($reason);
        foreach (['buyer', 'supply_date', 'subtype'] as $inherit) {
            if (! array_key_exists($inherit, $payload) && array_key_exists($inherit, $base)) {
                $payload[$inherit] = $base[$inherit];
            }
        }

        $issuer = app($original->workflow === Workflow::B2B ? 'zatca.b2b' : 'zatca.b2c');

        return $issuer->issue($payload, [
            'device' => $options['device'] ?? (int) $original->device_id,
            'external_id' => $options['external_id'] ?? null,
            'actor_id' => $actorId,
            'billing_reference_invoice_id' => (int) $original->id,
        ]);
    }

    // ------------------------------------------------------------------ internals

    private function reprocess(Invoice $invoice, ?int $actorId): IssueResult
    {
        $invoice->refresh();
        $issuance = $this->currentIssuance($invoice);

        return $issuance === null
            ? $this->issueFirst($invoice, $actorId)
            : $this->issueAgain($invoice, IssuanceReason::Correction, $actorId);
    }

    private function issueFirst(Invoice $invoice, ?int $actorId): IssueResult
    {
        try {
            $this->processor->issue($invoice, $actorId);
        } catch (InvoiceValidationException|DeviceUnavailable|\CodingTriplets\Zatca\Core\Domain\Exceptions\CredentialException) {
            // recorded by the processor
        }

        return IssueResult::fromInvoice($invoice, false);
    }

    private function issueAgain(Invoice $invoice, IssuanceReason $reason, ?int $actorId): IssueResult
    {
        $this->chain->issue($invoice->refresh(), $reason, $actorId);

        return IssueResult::fromInvoice($invoice, false);
    }

    private function assertCorrectable(Invoice $invoice, ?InvoiceIssuance $issuance): void
    {
        if ($issuance === null) {
            if (! in_array($invoice->processing_status, [ProcessingStatus::ValidationFailed, ProcessingStatus::Failed, ProcessingStatus::ReadyForProcessing, ProcessingStatus::ManualReview], true)) {
                throw OperationNotAllowed::because($invoice, "it is {$invoice->processing_status->value}");
            }

            return;
        }

        if (in_array($issuance->status, [IssuanceStatus::Accepted, IssuanceStatus::AcceptedWithWarnings], true)) {
            throw OperationNotAllowed::because($invoice, 'it was accepted by ZATCA; issue a credit or debit note instead');
        }
        if ($issuance->status !== IssuanceStatus::Rejected) {
            throw OperationNotAllowed::because($invoice, 'its document has no final ZATCA result yet; retry the submission instead');
        }
        if (! in_array($invoice->processing_status, [ProcessingStatus::Rejected, ProcessingStatus::ManualReview], true)) {
            throw OperationNotAllowed::because($invoice, "it is {$invoice->processing_status->value}");
        }
    }

    private function find(Invoice|string $invoice): Invoice
    {
        return $invoice instanceof Invoice ? $invoice->refresh() : Invoice::query()->where('public_id', $invoice)->firstOrFail();
    }

    private function currentIssuance(Invoice $invoice): ?InvoiceIssuance
    {
        return $invoice->current_issuance_id !== null ? InvoiceIssuance::query()->find($invoice->current_issuance_id) : null;
    }

    /** @return class-string<B2bClearanceSubmission|B2cReportingSubmission> */
    private function submissionModel(Workflow $workflow): string
    {
        return $workflow === Workflow::B2B ? B2bClearanceSubmission::class : B2cReportingSubmission::class;
    }

    /**
     * JSON pointers of the fields that differ between two payloads.
     *
     * @param array<mixed> $a
     * @param array<mixed> $b
     * @return list<string>
     */
    public static function changedFields(array $a, array $b, string $prefix = ''): array
    {
        $changed = [];
        foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $key) {
            $path = $prefix . '/' . str_replace(['~', '/'], ['~0', '~1'], (string) $key);
            $x = $a[$key] ?? null;
            $y = $b[$key] ?? null;
            if (is_array($x) && is_array($y)) {
                $changed = array_merge($changed, self::changedFields($x, $y, $path));
            } elseif ($x !== $y) {
                $changed[] = $path;
            }
        }

        return $changed;
    }
}
