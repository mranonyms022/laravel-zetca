<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Invoices;

use CodingTriplets\Zatca\Core\Contracts\Persistence\TransactionManager;
use CodingTriplets\Zatca\Core\Domain\Enums\ClearanceStatus;
use CodingTriplets\Zatca\Core\Domain\Enums\DocumentType;
use CodingTriplets\Zatca\Core\Domain\Enums\ErrorCategory;
use CodingTriplets\Zatca\Core\Domain\Enums\ProcessingStatus;
use CodingTriplets\Zatca\Core\Domain\Enums\ReportingStatus;
use CodingTriplets\Zatca\Core\Domain\Enums\Severity;
use CodingTriplets\Zatca\Core\Domain\Enums\SubmissionStatus;
use CodingTriplets\Zatca\Core\Domain\Enums\ValidationStatus;
use CodingTriplets\Zatca\Core\Domain\Enums\VersionKind;
use CodingTriplets\Zatca\Core\Domain\Enums\Workflow;
use CodingTriplets\Zatca\Core\Domain\ValueObjects\InvoiceTypeFlags;
use CodingTriplets\Zatca\Core\Invoice\Shared\Calculation\InvoiceTotals;
use CodingTriplets\Zatca\Core\Invoice\Shared\Validation\InvoicePayloadMapper;
use CodingTriplets\Zatca\Core\Invoice\Shared\Validation\ValidationResult;
use CodingTriplets\Zatca\Laravel\Database\Models\Device;
use CodingTriplets\Zatca\Laravel\Database\Models\Invoice;
use CodingTriplets\Zatca\Laravel\Database\Models\InvoiceVersion;
use CodingTriplets\Zatca\Laravel\Database\Models\Warning;
use CodingTriplets\Zatca\Laravel\Processing\ErrorRecorder;
use CodingTriplets\Zatca\Laravel\Processing\Outbox\OutboxRecorder;
use CodingTriplets\Zatca\Laravel\Processing\ProcessingLogger;
use Illuminate\Database\QueryException;

/**
 * T1 intake + T1.5 validation (ARCHITECTURE 14.1), in one short transaction.
 *
 * - Idempotent: the same `external_invoice_id` for a company returns the existing invoice (safe client retries).
 *   A different payload under the same ID is refused (DuplicateInvoice); nothing is overwritten.
 * - The original payload is stored unmodified as version 1 (append-only).
 * - Invalid payloads are kept with status VALIDATION_FAILED and field-level errors; no ICV is ever used for them.
 */
final class InvoiceIntakeService
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly InvoiceGenerators $generators,
        private readonly PayloadPreparer $preparer,
        private readonly ProcessingLogger $logger,
        private readonly ErrorRecorder $errors,
        private readonly OutboxRecorder $outbox,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @param array{device?: int|string|Device, external_id?: string, actor_id?: ?int, queue_processing?: bool, billing_reference_invoice_id?: int, source?: \Illuminate\Database\Eloquent\Model|array{type: string, id: int|string}} $options
     */
    public function receive(Workflow $workflow, array $payload, array $options = []): IntakeResult
    {
        $device = $this->device($options['device'] ?? $payload['device_id'] ?? $payload['device_uuid'] ?? null);
        if (! InvoiceTypeFlags::fromString((string) $device->invoice_type_flags)->supports($workflow)) {
            throw DeviceUnavailable::because((int) $device->id, "it is not registered for {$workflow->value} invoices");
        }

        $externalId = trim((string) ($options['external_id'] ?? $payload['external_invoice_id'] ?? $payload['invoice_number'] ?? ''));
        if ($externalId === '' || mb_strlen($externalId) > 191) {
            throw new \InvalidArgumentException('external_invoice_id (or invoice_number) is required and must be at most 191 characters.');
        }

        $payloadHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($existing = $this->existing((int) $device->company_id, $externalId, $payloadHash)) {
            return new IntakeResult($existing, false);
        }

        $prepared = $this->preparer->prepare($payload, $workflow, $device);
        $validation = $this->generators->for($workflow)->validate($prepared);
        $document = $validation->isValid() ? (new InvoicePayloadMapper())->map($prepared, new ValidationResult(), $workflow) : null;
        $totals = $document !== null ? InvoiceTotals::calculate($document) : null;

        try {
            $invoice = $this->transactions->run(fn (): Invoice => $this->store(
                $workflow, $device, $externalId, $payload, $payloadHash, $validation, $document, $totals, $options,
            ));
        } catch (QueryException $e) {
            // Same external ID received concurrently: return the winner (or refuse a different payload).
            $existing = $this->existing((int) $device->company_id, $externalId, $payloadHash);
            if ($existing === null) {
                throw $e;
            }

            return new IntakeResult($existing, false);
        }

        return new IntakeResult($invoice, true, $validation);
    }

    /**
     * The host application's record: an Eloquent model (any table) or ['type' => ..., 'id' => ...].
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function source(mixed $source): array
    {
        if ($source instanceof \Illuminate\Database\Eloquent\Model) {
            if ($source->getKey() === null) {
                throw new \InvalidArgumentException('The source model must be saved (it has no key).');
            }

            return [mb_substr($source->getMorphClass(), 0, 191), mb_substr((string) $source->getKey(), 0, 64)];
        }
        if (is_array($source) && isset($source['type'], $source['id']) && (string) $source['id'] !== '') {
            return [mb_substr((string) $source['type'], 0, 191), mb_substr((string) $source['id'], 0, 64)];
        }
        if ($source !== null) {
            throw new \InvalidArgumentException("The 'source' option must be an Eloquent model or ['type' => ..., 'id' => ...].");
        }

        return [null, null];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     */
    private function store(
        Workflow $workflow,
        Device $device,
        string $externalId,
        array $payload,
        string $payloadHash,
        ValidationResult $validation,
        ?\CodingTriplets\Zatca\Core\Invoice\Shared\DTOs\InvoiceDocument $document,
        ?InvoiceTotals $totals,
        array $options,
    ): Invoice {
        $valid = $validation->isValid();
        $type = DocumentType::tryFrom((string) ($document?->type->value ?? '')) ?? $this->documentType($payload);
        $issuedAt = $document?->issuedAt ?? new \DateTimeImmutable('now', new \DateTimeZone('Asia/Riyadh'));

        $invoice = new Invoice([
            'public_id' => Ulid::generate(),
            'tenant_id' => (string) $device->tenant_id,
            'company_id' => $device->company_id,
            'vat_registration_id' => $device->vat_registration_id,
            'branch_id' => $device->branch_id,
            'device_id' => $device->id,
            'environment' => $device->environment,
            'workflow' => $workflow,
            'external_invoice_id' => $externalId,
            'invoice_number' => mb_substr(trim((string) ($payload['invoice_number'] ?? $externalId)), 0, 191),
            'document_type' => $type,
            'subtype_flags' => $document?->subtypeFlags ?? ($workflow->subtypePrefix() . '00000'),
            'invoice_date' => $issuedAt->format('Y-m-d'),
            'currency' => 'SAR',
            'total_excl_vat' => $totals?->taxExclusive->format(2) ?? '0.00',
            'vat_total' => $totals?->taxTotal->format(2) ?? '0.00',
            'total_incl_vat' => $totals?->taxInclusive->format(2) ?? '0.00',
            'buyer_vat_number' => $document?->buyer?->vatNumber !== null ? mb_substr($document->buyer->vatNumber, 0, 15) : null,
            'processing_status' => ProcessingStatus::Received,
            'validation_status' => ValidationStatus::Pending,
            'submission_status' => SubmissionStatus::NotStarted,
            'reporting_status' => $workflow === Workflow::B2C ? ReportingStatus::Pending : ReportingStatus::NotApplicable,
            'clearance_status' => $workflow === Workflow::B2B ? ClearanceStatus::Pending : ClearanceStatus::NotApplicable,
            'warning_count' => count($validation->warnings()),
            'created_by' => $options['actor_id'] ?? null,
            'billing_reference_invoice_id' => $options['billing_reference_invoice_id'] ?? null,
            'source_type' => self::source($options['source'] ?? null)[0],
            'source_id' => self::source($options['source'] ?? null)[1],
            'status_changed_at' => now(),
        ]);
        $invoice->save();

        $version = InvoiceVersion::query()->create([
            'invoice_id' => $invoice->id,
            'version' => 1,
            'kind' => VersionKind::Original,
            'payload' => $payload,
            'payload_sha256' => $payloadHash,
            'validation_result' => ['valid' => $valid, 'errors' => $validation->errors(), 'warnings' => $validation->warnings()],
            'created_by' => $options['actor_id'] ?? null,
        ]);
        $invoice->current_version_id = $version->id;

        $this->logger->log($invoice, 'processing', 'received', null, ProcessingStatus::Received->value, actorId: $options['actor_id'] ?? null);
        $this->logger->transition($invoice, [
            'validation_status' => ! $valid ? ValidationStatus::Invalid : ($validation->warnings() === [] ? ValidationStatus::Valid : ValidationStatus::ValidWithWarnings),
            'processing_status' => $valid ? ProcessingStatus::ReadyForProcessing : ProcessingStatus::ValidationFailed,
        ], 'validated');
        $invoice->save();

        foreach ($validation->warnings() as $w) {
            Warning::query()->create([
                'tenant_id' => (string) $invoice->tenant_id,
                'invoice_id' => $invoice->id,
                'workflow' => $workflow,
                'source' => 'local',
                'code' => mb_substr($w['code'], 0, 64),
                'message' => "{$w['field']}: {$w['message']}",
                'details' => ['field' => $w['field']],
            ]);
        }
        foreach (array_slice($validation->errors(), 0, 50) as $e) {
            $this->errors->record(
                $e['code'] === 'FORMAT' ? ErrorCategory::JsonValidation : ErrorCategory::BusinessRule,
                Severity::Error,
                $e['code'],
                "{$e['field']}: {$e['message']}",
                'intake.validation',
                ['invoice' => $invoice, 'details' => ['field' => $e['field']]],
            );
        }

        if ($valid && ($options['queue_processing'] ?? false)) {
            $this->outbox->record('invoice', (int) $invoice->id, OutboxRecorder::EVENT_PROCESS, QueueNames::generation($workflow), "process:{$invoice->public_id}:1", tenantId: (string) $invoice->tenant_id);
        }

        return $invoice;
    }

    private function existing(int $companyId, string $externalId, string $payloadHash): ?Invoice
    {
        $invoice = Invoice::query()->withoutGlobalScopes()->where('company_id', $companyId)->where('external_invoice_id', $externalId)->first();
        if ($invoice === null) {
            return null;
        }

        $original = InvoiceVersion::query()->where('invoice_id', $invoice->id)->where('version', 1)->value('payload_sha256');
        if ($original !== null && ! hash_equals((string) $original, $payloadHash)) {
            throw DuplicateInvoice::differentPayload($externalId, (string) $invoice->public_id);
        }

        return $invoice;
    }

    private function device(mixed $device): Device
    {
        if ($device instanceof Device) {
            return $device;
        }
        if ($device === null || $device === '') {
            throw new \InvalidArgumentException('The issuing device is required (option "device", or payload "device_id" / "device_uuid").');
        }

        $query = Device::query();
        $found = is_int($device) || ctype_digit((string) $device)
            ? $query->find((int) $device)
            : $query->where('device_uuid', (string) $device)->first();

        if ($found === null) {
            throw new \InvalidArgumentException("Device [{$device}] was not found.");
        }

        return $found;
    }

    /** @param array<string, mixed> $payload */
    private function documentType(array $payload): DocumentType
    {
        $map = ['invoice' => '388', 'credit_note' => '381', 'debit_note' => '383'];

        return DocumentType::tryFrom($map[strtolower((string) ($payload['type'] ?? 'invoice'))] ?? (string) ($payload['type'] ?? '')) ?? DocumentType::Invoice;
    }
}
