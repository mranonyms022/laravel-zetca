<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Invoices;

use CodingTriplets\Zatca\Core\Contracts\Persistence\TransactionManager;
use CodingTriplets\Zatca\Core\Domain\Enums\ErrorCategory;
use CodingTriplets\Zatca\Core\Domain\Enums\ProcessingStatus;
use CodingTriplets\Zatca\Core\Domain\Enums\Severity;
use CodingTriplets\Zatca\Core\Domain\Enums\ValidationStatus;
use CodingTriplets\Zatca\Core\Domain\Exceptions\CredentialException;
use CodingTriplets\Zatca\Core\Invoice\Shared\Validation\InvoiceValidationException;
use CodingTriplets\Zatca\Laravel\Database\Models\Invoice;
use CodingTriplets\Zatca\Laravel\Database\Models\InvoiceIssuance;
use CodingTriplets\Zatca\Laravel\Processing\ErrorRecorder;
use CodingTriplets\Zatca\Laravel\Processing\ProcessingLogger;

/**
 * Turns a validated invoice into an issued document (T2) and records failures that need a human.
 * Used synchronously (B2C POS, `issue()`) and by the generation queue (B2B `submit()`).
 */
final class InvoiceProcessor
{
    public function __construct(
        private readonly ChainService $chain,
        private readonly TransactionManager $transactions,
        private readonly ProcessingLogger $logger,
        private readonly ErrorRecorder $errors,
    ) {
    }

    /** Queue entry point: idempotent, does nothing unless the invoice is ready. */
    public function process(int $invoiceId): ?InvoiceIssuance
    {
        $invoice = Invoice::query()->withoutGlobalScopes()->find($invoiceId);
        if ($invoice === null) {
            return null;
        }
        if ($invoice->current_issuance_id !== null) {
            return InvoiceIssuance::query()->find($invoice->current_issuance_id);
        }
        if ($invoice->processing_status !== ProcessingStatus::ReadyForProcessing) {
            return null;
        }

        return $this->issue($invoice);
    }

    /**
     * @throws InvoiceValidationException|DeviceUnavailable|CredentialException (after the failure is recorded)
     */
    public function issue(Invoice $invoice, ?int $actorId = null, int $deferSubmissionSeconds = 0): InvoiceIssuance
    {
        try {
            return $this->chain->issue($invoice, actorId: $actorId, deferSubmissionSeconds: $deferSubmissionSeconds);
        } catch (InvoiceValidationException $e) {
            $this->fail($invoice, ProcessingStatus::ValidationFailed, ErrorCategory::BusinessRule, Severity::Error, $e, ValidationStatus::Invalid);

            throw $e;
        } catch (DeviceUnavailable|CredentialException $e) {
            $this->fail($invoice, ProcessingStatus::Failed, ErrorCategory::Certificate, Severity::Critical, $e);

            throw $e;
        }
    }

    private function fail(Invoice $invoice, ProcessingStatus $status, ErrorCategory $category, Severity $severity, \Throwable $e, ?ValidationStatus $validation = null): void
    {
        $this->transactions->run(function () use ($invoice, $status, $category, $severity, $e, $validation): void {
            $locked = Invoice::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($invoice->id);
            if ($locked->current_issuance_id !== null) {
                return;
            }
            $changes = ['processing_status' => $status] + ($validation !== null ? ['validation_status' => $validation] : []);
            $this->logger->transition($locked, $changes, 'issuance_failed', $e->getMessage());
            $locked->save();

            if ($e instanceof InvoiceValidationException) {
                foreach (array_slice($e->result->errors(), 0, 50) as $err) {
                    $this->errors->record($category, $severity, $err['code'], "{$err['field']}: {$err['message']}", 'issuance.validation', ['invoice' => $locked]);
                }

                return;
            }
            $this->errors->record($category, $severity, class_basename($e), $e->getMessage(), 'issuance', ['invoice' => $locked]);
        });
    }
}
