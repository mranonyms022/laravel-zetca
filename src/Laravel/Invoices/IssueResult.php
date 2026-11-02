<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Invoices;

use CodingTriplets\Zatca\Core\Domain\Enums\ProcessingStatus;
use CodingTriplets\Zatca\Core\Domain\Enums\Workflow;
use CodingTriplets\Zatca\Laravel\Database\Models\Invoice;
use CodingTriplets\Zatca\Laravel\Database\Models\InvoiceIssuance;
use CodingTriplets\Zatca\Laravel\Database\Models\InvoiceVersion;
use CodingTriplets\Zatca\Laravel\Database\Models\ZatcaError;

/**
 * Result of `Zatca::b2c()->issue()` / `Zatca::b2b()->issue()` and the HTTP API (ARCHITECTURE 11.1).
 *
 * Queue mode success:
 *   {"status": true, "message": "invoice queued", "qr": "...", "counter": 10452, "uuid": "...",
 *    "invoice_id": "01J...", "invoice_hash": "...", "reporting_deadline": "2026-11-03T09:14:03Z"}
 */
final class IssueResult
{
    /** @param list<array{field?: string, code: ?string, message: string}> $errors */
    private function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly ?Invoice $invoice,
        public readonly ?InvoiceIssuance $issuance,
        public readonly bool $replayed,
        public readonly array $errors = [],
        public readonly int $httpStatus = 201,
    ) {
    }

    public static function fromInvoice(Invoice $invoice, bool $replayed): self
    {
        $invoice->refresh();
        $issuance = $invoice->current_issuance_id !== null ? InvoiceIssuance::query()->find($invoice->current_issuance_id) : null;

        if ($issuance === null) {
            $errors = self::validationErrors($invoice);
            $status = $invoice->processing_status;
            $message = match ($status) {
                ProcessingStatus::ValidationFailed => 'validation failed',
                ProcessingStatus::Failed => 'invoice could not be issued',
                default => 'invoice accepted for processing',
            };
            $ok = ! in_array($status, [ProcessingStatus::ValidationFailed, ProcessingStatus::Failed], true);

            return new self($ok, $message, $invoice, null, $replayed, $errors, $ok ? ($replayed ? 200 : 202) : 422);
        }

        $message = match ($invoice->processing_status) {
            ProcessingStatus::Reported => 'invoice reported',
            ProcessingStatus::Cleared => 'invoice cleared',
            ProcessingStatus::Rejected => $invoice->workflow === Workflow::B2B ? 'invoice not cleared' : 'invoice not reported',
            default => 'invoice queued',
        };
        $errors = $invoice->processing_status === ProcessingStatus::Rejected
            ? ZatcaError::query()->withoutGlobalScopes()->where('invoice_id', $invoice->id)->where('issuance_id', $issuance->id)->orderBy('id')->limit(50)
                ->get(['code', 'message'])->map(static fn ($e) => ['code' => $e->code, 'message' => (string) $e->message])->all()
            : [];

        return new self(true, $message, $invoice, $issuance, $replayed, $errors, $replayed ? 200 : 201);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        if ($this->issuance === null) {
            return array_filter([
                'status' => $this->success,
                'message' => $this->message,
                'invoice_id' => $this->invoice?->public_id,
                'processing_status' => $this->invoice?->processing_status->value,
                'errors' => $this->errors === [] ? null : $this->errors,
            ], static fn ($v) => $v !== null);
        }

        $invoice = $this->invoice;
        assert($invoice !== null);

        return array_filter([
            'status' => $this->success,
            'message' => $this->message,
            'qr' => (string) $this->issuance->qr_payload,
            'counter' => (int) $this->issuance->icv,
            'uuid' => (string) $this->issuance->uuid,
            'invoice_id' => (string) $invoice->public_id,
            'invoice_hash' => (string) $this->issuance->invoice_hash,
            'processing_status' => $invoice->processing_status->value,
            'reporting_deadline' => $invoice->reporting_deadline_at?->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'errors' => $this->errors === [] ? null : $this->errors,
        ], static fn ($v) => $v !== null);
    }

    /** @return list<array{field: string, code: string, message: string}> */
    private static function validationErrors(Invoice $invoice): array
    {
        $version = InvoiceVersion::query()->find($invoice->current_version_id);
        $errors = (array) (($version?->validation_result ?? [])['errors'] ?? []);
        if ($errors !== []) {
            return array_values($errors);
        }

        return ZatcaError::query()->withoutGlobalScopes()->where('invoice_id', $invoice->id)->orderBy('id')->limit(50)
            ->get(['code', 'message'])->map(static fn ($e) => ['field' => '', 'code' => (string) $e->code, 'message' => (string) $e->message])->all();
    }
}
