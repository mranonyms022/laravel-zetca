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
        private readonly \CodingTriplets\Zatca\Core\Contracts\Locks\AdvisoryLock $locks,
        private readonly \CodingTriplets\Zatca\Core\Contracts\Events\EventDispatcher $events,
        private readonly \CodingTriplets\Zatca\Laravel\Audit\AuditLogger $audit,
    ) {
    }

    public function retry(Invoice|string $invoice, ?int $actorId = null, bool $now = false): string
    {
        return $this->audited('invoice.retry', $invoice, [], $actorId, fn () => $this->performRetry($invoice, $actorId, $now));
    }

    public function correct(Invoice|string $invoice, array $payload, string $reason, ?int $actorId = null): IssueResult
    {
        return $this->audited('invoice.correct', $invoice, ['reason' => $reason], $actorId, fn () => $this->performCorrect($invoice, $payload, $reason, $actorId));
    }

    public function reissueUnchanged(Invoice|string $invoice, string $reason, ?int $actorId = null): IssueResult
    {
        return $this->audited('invoice.reissue', $invoice, ['reason' => $reason], $actorId, fn () => $this->performReissueUnchanged($invoice, $reason, $actorId));
    }

    public function approve(Invoice|string $invoice, int $approverId): IssueResult
    {
        return $this->audited('invoice.approve', $invoice, [], $approverId, fn () => $this->performApprove($invoice, $approverId));
    }

    public function creditNote(Invoice|string $original, array $payload, string $reason, ?int $actorId = null, array $options = []): IssueResult
    {
        return $this->audited('invoice.credit_note', $original, ['reason' => $reason], $actorId, fn () => $this->performCreditNote($original, $payload, $reason, $actorId, $options));
    }

    public function debitNote(Invoice|string $original, array $payload, string $reason, ?int $actorId = null, array $options = []): IssueResult
    {
        return $this->audited('invoice.debit_note', $original, ['reason' => $reason], $actorId, fn () => $this->performDebitNote($original, $payload, $reason, $actorId, $options));
    }

    public function cancel(Invoice|string $original, string $reason, ?int $actorId = null, array $options = []): IssueResult
    {
        return $this->audited('invoice.cancel', $original, ['reason' => $reason], $actorId, fn () => $this->performCancel($original, $reason, $actorId, $options));
    }

    public function discard(Invoice|string $invoice, string $reason, ?int $actorId = null): Invoice
    {
        return $this->audited('invoice.discard', $invoice, ['reason' => $reason], $actorId, fn () => $this->performDiscard($invoice, $reason, $actorId));
    }

    public function abandon(Invoice|string $invoice, string $reason, ?int $actorId = null): Invoice
    {
        return $this->audited('invoice.abandon', $invoice, ['reason' => $reason], $actorId, fn () => $this->performAbandon($invoice, $reason, $actorId));
    }

    /**
     * Runs an operator action and, when it succeeds, appends it to the hash-chained audit log
     * (action, invoice, reason, resulting document, actor). Failed attempts are not audited: they changed nothing.
     *
     * @template T
     * @param array<string, mixed> $changes
     * @param \Closure(): T $operation
     * @return T
     */
    private function audited(string $action, Invoice|string $invoice, array $changes, ?int $actorId, \Closure $operation): mixed
    {
        $result = $operation();

        $subject = $invoice instanceof Invoice ? $invoice : Invoice::query()->where('public_id', $invoice)->first();
        if ($result instanceof IssueResult) {
            $data = $result->toArray();
            $changes += ['document' => $data['invoice_id'] ?? null, 'counter' => $data['counter'] ?? null, 'uuid' => $data['uuid'] ?? null];
        } elseif ($result instanceof Invoice) {
            $changes += ['status' => $result->processing_status->value];
        } elseif (is_string($result)) {
            $changes += ['submission' => $result];
        }
        $this->audit->record($action, 'invoice', $subject?->id === null ? null : (int) $subject->id,
            array_filter($changes + ['invoice' => $subject?->public_id], static fn ($v) => $v !== null), $actorId);

        return $result;
    }

    // ------------------------------------------------------------------ A. Retry submission

    /**
     * Resend the current signed document unchanged. UUID, ICV, PIH, hash, signature and QR stay the same.
     *
     * @param bool $now submit inside this call instead of queuing it
     * @return string the submission status
     */
    private function performRetry(Invoice|string $invoice, ?int $actorId = null, bool $now = false): string
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
     * Dry run of a correction: the same checks and validation as correct(), nothing is saved or signed.
     *
     * @param array<string, mixed> $payload the full corrected invoice JSON
     * @throws OperationNotAllowed when the invoice cannot be corrected in its current state
     */
    public function previewCorrection(Invoice|string $invoice, array $payload): ValidationResult
    {
        return $this->prepareCorrection($this->find($invoice), $payload)['validation'];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{issuance: ?InvoiceIssuance, invoice_number: string, prepared: array<string, mixed>, validation: ValidationResult}
     */
    private function prepareCorrection(Invoice $invoice, array $payload): array
    {
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

        return ['issuance' => $issuance, 'invoice_number' => $invoiceNumber, 'prepared' => $prepared, 'validation' => $validation];
    }


    /**
     * Save a corrected JSON version (append-only; the original stays) and, unless approval is required,
     * reprocess it. For a rejected document this creates issuance n+1 with a new UUID, the next ICV and the
     * chain-head PIH. The rejected document stays in the chain.
     *
     * @param array<string, mixed> $payload the full corrected invoice JSON
     * @throws InvoiceValidationException nothing is saved when the correction is invalid
     */
    private function performCorrect(Invoice|string $invoice, array $payload, string $reason, ?int $actorId = null): IssueResult
    {
        $invoice = $this->find($invoice);
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('A reason is required for every correction.');
        }

        ['issuance' => $issuance, 'invoice_number' => $invoiceNumber, 'prepared' => $prepared, 'validation' => $validation] = $this->prepareCorrection($invoice, $payload);
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

        $result = $fourEyes ? IssueResult::fromInvoice($invoice, false) : $this->reprocess($invoice, $actorId);
        if ($invoice->billing_reference_invoice_id !== null && ($original = Invoice::query()->withoutGlobalScopes()->find($invoice->billing_reference_invoice_id)) !== null) {
            $this->refreshAdjustments($original);   // a corrected note may have a different total
        }

        return $result;
    }

    /** New issuance from the unchanged JSON, for a rejection caused by the package or a certificate. */
    private function performReissueUnchanged(Invoice|string $invoice, string $reason, ?int $actorId = null): IssueResult
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
    private function performApprove(Invoice|string $invoice, int $approverId): IssueResult
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
    private function performCreditNote(Invoice|string $original, array $payload, string $reason, ?int $actorId = null, array $options = []): IssueResult
    {
        return $this->note('credit_note', $original, $payload, $reason, $actorId, $options);
    }

    /** Increase an accepted invoice with a debit note (383) in the same workflow. */
    private function performDebitNote(Invoice|string $original, array $payload, string $reason, ?int $actorId = null, array $options = []): IssueResult
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
        $device = $options['device'] ?? (int) $original->device_id;

        // Serialise notes per original so two concurrent credit notes cannot both pass the over-credit check.
        return $this->locks->withLock("zatca:notes:{$original->id}", 30, 10, function () use ($type, $original, $payload, $issuer, $device, $options, $actorId): IssueResult {
            if ($type === 'credit_note') {
                $this->assertCreditable($original, $payload, $device);
            }
            $result = $issuer->issue($payload, array_filter([
                'device' => $device,
                'external_id' => $options['external_id'] ?? null,
                'actor_id' => $actorId,
                'billing_reference_invoice_id' => (int) $original->id,
                // a note belongs to the same host record as its original unless told otherwise
                'source' => $options['source'] ?? ($original->source_type !== null ? ['type' => $original->source_type, 'id' => (string) $original->source_id] : null),
            ], static fn ($v) => $v !== null));
            $this->refreshAdjustments($original);

            return $result;
        });
    }

    // ------------------------------------------------------------------ D. Cancellation

    /**
     * Cancel an accepted invoice completely: a credit note (381) with all lines, allowances and charges of
     * the original, in the same workflow. ZATCA has no separate cancellation document.
     *
     * @param array{invoice_number?: string, device?: int|string, external_id?: string, source?: mixed} $options
     *        invoice_number defaults to "{original number}-CN"
     */
    private function performCancel(Invoice|string $original, string $reason, ?int $actorId = null, array $options = []): IssueResult
    {
        $original = $this->find($original);
        if ($original->cancellation_invoice_id !== null) {
            throw OperationNotAllowed::because($original, 'it is already cancelled');
        }
        $this->refreshAdjustments($original);
        if (! \CodingTriplets\Zatca\Core\Invoice\Shared\Calculation\Amount::of((string) $original->credited_amount)->isZero()) {
            throw OperationNotAllowed::because($original, 'it already has credit notes; credit the remaining ' . $original->creditableAmount() . ' SAR with creditNote()');
        }

        $base = (array) InvoiceVersion::query()->whereKey($original->current_version_id)->value('payload');
        $payload = array_filter([
            'invoice_number' => $options['invoice_number'] ?? ($original->invoice_number . '-CN'),
            'lines' => $base['lines'] ?? [],
            'allowances' => $base['allowances'] ?? null,
            'charges' => $base['charges'] ?? null,
            'payment_means_code' => $base['payment_means_code'] ?? null,
        ], static fn ($v) => $v !== null);

        $result = $this->creditNote($original, $payload, $reason, $actorId, $options);
        if ($result->invoice !== null && $result->success) {
            $this->transactions->run(function () use ($original, $result, $reason, $actorId): void {
                $locked = Invoice::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($original->id);
                $locked->cancellation_invoice_id = $result->invoice->id;
                $this->logger->log($locked, 'operation', 'cancelled', null, null, "Cancelled by credit note {$result->invoice->public_id}: {$reason}", [], null, $actorId);
                $locked->save();
                $this->events->dispatchAfterCommit(new \CodingTriplets\Zatca\Core\Events\InvoiceCancelled(
                    (int) $locked->id, (string) $locked->public_id, 'credit_note', $reason, (string) $result->invoice->public_id, $actorId,
                    sourceType: $locked->source_type, sourceId: $locked->source_id === null ? null : (string) $locked->source_id,
                ));
            });
        }

        return $result;
    }

    /** Withdraw an invoice that was never signed (no ICV used, nothing sent to ZATCA). */
    private function performDiscard(Invoice|string $invoice, string $reason, ?int $actorId = null): Invoice
    {
        $invoice = $this->find($invoice);
        $this->requireReason($reason);
        $allowed = [ProcessingStatus::ValidationFailed, ProcessingStatus::ReadyForProcessing, ProcessingStatus::Failed, ProcessingStatus::ManualReview];

        $this->transactions->run(function () use ($invoice, $reason, $actorId, $allowed): void {
            $locked = Invoice::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($invoice->id);
            if ($locked->current_issuance_id !== null) {
                throw OperationNotAllowed::because($locked, 'it has a signed document; use cancel() (accepted) or abandon() (rejected)');
            }
            if (! in_array($locked->processing_status, $allowed, true)) {
                throw OperationNotAllowed::because($locked, "it is {$locked->processing_status->value}");
            }
            $this->close($locked, ProcessingStatus::Cancelled, 'discarded', $reason, $actorId);
        });

        return $this->afterClose($invoice);
    }

    /**
     * Close an invoice whose document ZATCA rejected and that is no longer needed. The rejected document
     * stays in the chain and in the history. ⚠ ZATCA guidance on abandoning rejected documents is not explicit.
     */
    private function performAbandon(Invoice|string $invoice, string $reason, ?int $actorId = null): Invoice
    {
        $invoice = $this->find($invoice);
        $this->requireReason($reason);

        $this->transactions->run(function () use ($invoice, $reason, $actorId): void {
            $locked = Invoice::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($invoice->id);
            $issuance = $this->currentIssuance($locked);
            if ($issuance === null || $issuance->status !== IssuanceStatus::Rejected) {
                throw OperationNotAllowed::because($locked, 'only an invoice whose document ZATCA rejected can be abandoned');
            }
            if (! in_array($locked->processing_status, [ProcessingStatus::Rejected, ProcessingStatus::ManualReview], true)) {
                throw OperationNotAllowed::because($locked, "it is {$locked->processing_status->value}");
            }
            ZatcaError::query()->where('invoice_id', $locked->id)->where('is_resolved', false)->update([
                'is_resolved' => true, 'resolved_at' => now(), 'resolved_by' => $actorId, 'resolution_note' => 'abandoned: ' . mb_substr($reason, 0, 200),
            ]);
            $locked->unresolved_error_count = 0;
            $this->close($locked, ProcessingStatus::Abandoned, 'abandoned', $reason, $actorId);
        });

        return $this->afterClose($invoice);
    }

    private function close(Invoice $locked, ProcessingStatus $status, string $kind, string $reason, ?int $actorId): void
    {
        $this->logger->transition($locked, ['processing_status' => $status], $kind, $reason, $locked->current_issuance_id, $actorId);
        $locked->closed_at = now();
        $locked->requires_approval = false;
        $locked->next_retry_at = null;
        $locked->save();
        $this->events->dispatchAfterCommit(new \CodingTriplets\Zatca\Core\Events\InvoiceCancelled(
            (int) $locked->id, (string) $locked->public_id, $kind, $reason, null, $actorId,
                    sourceType: $locked->source_type, sourceId: $locked->source_id === null ? null : (string) $locked->source_id,
                ));
    }

    private function afterClose(Invoice $invoice): Invoice
    {
        $invoice->refresh();
        if ($invoice->billing_reference_invoice_id !== null) {
            $original = Invoice::query()->withoutGlobalScopes()->find($invoice->billing_reference_invoice_id);
            if ($original !== null) {
                $this->refreshAdjustments($original);
            }
        }

        return $invoice;
    }

    private function requireReason(string $reason): void
    {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('A reason is required.');
        }
    }

    /**
     * Refuses a credit note that would take the credited total above the invoice total (+ debit notes).
     *
     * @param array<string, mixed> $payload
     */
    private function assertCreditable(Invoice $original, array $payload, int|string $device): void
    {
        $deviceModel = is_int($device) || ctype_digit((string) $device)
            ? Device::query()->withoutGlobalScopes()->find((int) $device)
            : Device::query()->withoutGlobalScopes()->where('device_uuid', (string) $device)->first();
        if ($deviceModel === null) {
            return;   // the issuer reports the missing device
        }
        $prepared = $this->preparer->prepare($payload, $original->workflow, $deviceModel, (string) ($payload['invoice_number'] ?? ''));
        $document = (new InvoicePayloadMapper())->map($prepared, new ValidationResult(), $original->workflow);
        if ($document === null) {
            return;   // invalid: the issuer returns the validation errors
        }
        $noteTotal = InvoiceTotals::calculate($document)->taxInclusive;
        $this->refreshAdjustments($original);
        $remaining = \CodingTriplets\Zatca\Core\Invoice\Shared\Calculation\Amount::of($original->creditableAmount());
        if ($noteTotal->compare($remaining) > 0) {
            throw OperationNotAllowed::because($original, "the credit note ({$noteTotal->format(2)} SAR) exceeds the amount that can still be credited ({$remaining->format(2)} SAR)");
        }
    }

    /**
     * Recomputes credited_amount / debited_amount of an original invoice from its notes. Notes count unless
     * they failed validation, were discarded or abandoned (rejected notes still count until abandoned or corrected).
     */
    public function refreshAdjustments(Invoice $original): void
    {
        $excluded = [ProcessingStatus::ValidationFailed->value, ProcessingStatus::Cancelled->value, ProcessingStatus::Abandoned->value];
        $sums = Invoice::query()->withoutGlobalScopes()
            ->where('billing_reference_invoice_id', $original->id)
            ->whereNotIn('processing_status', $excluded)
            ->selectRaw('document_type, SUM(total_incl_vat) AS total')
            ->groupBy('document_type')
            ->pluck('total', 'document_type');

        Invoice::query()->withoutGlobalScopes()->whereKey($original->id)->update([
            'credited_amount' => \CodingTriplets\Zatca\Core\Invoice\Shared\Calculation\Amount::of((string) ($sums[DocumentType::CreditNote->value] ?? '0'))->format(2),
            'debited_amount' => \CodingTriplets\Zatca\Core\Invoice\Shared\Calculation\Amount::of((string) ($sums[DocumentType::DebitNote->value] ?? '0'))->format(2),
        ]);
        $original->refresh();
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
