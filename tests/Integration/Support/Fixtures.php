<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Tests\Integration\Support;

use CodingTriplets\Zatca\Core\Domain\Enums\CertificateStatus;
use CodingTriplets\Zatca\Core\Domain\Enums\CertificateType;
use CodingTriplets\Zatca\Core\Domain\Enums\ClearanceStatus;
use CodingTriplets\Zatca\Core\Domain\Enums\DeviceLifecycleStatus;
use CodingTriplets\Zatca\Core\Domain\Enums\DocumentType;
use CodingTriplets\Zatca\Core\Domain\Enums\Environment;
use CodingTriplets\Zatca\Core\Domain\Enums\IssuanceReason;
use CodingTriplets\Zatca\Core\Domain\Enums\IssuanceStatus;
use CodingTriplets\Zatca\Core\Domain\Enums\ProcessingStatus;
use CodingTriplets\Zatca\Core\Domain\Enums\ReportingStatus;
use CodingTriplets\Zatca\Core\Domain\Enums\SubmissionStatus;
use CodingTriplets\Zatca\Core\Domain\Enums\ValidationStatus;
use CodingTriplets\Zatca\Core\Domain\Enums\VersionKind;
use CodingTriplets\Zatca\Core\Domain\Enums\Workflow;
use CodingTriplets\Zatca\Laravel\Database\Models\Certificate;
use CodingTriplets\Zatca\Laravel\Database\Models\Company;
use CodingTriplets\Zatca\Laravel\Database\Models\Device;
use CodingTriplets\Zatca\Laravel\Database\Models\DeviceKey;
use CodingTriplets\Zatca\Laravel\Database\Models\Invoice;
use CodingTriplets\Zatca\Laravel\Database\Models\InvoiceIssuance;
use CodingTriplets\Zatca\Laravel\Database\Models\InvoiceVersion;
use CodingTriplets\Zatca\Laravel\Database\Models\VatRegistration;
use Illuminate\Support\Str;

/** Minimal valid rows for persistence tests (real models, real database). */
final class Fixtures
{
    private static int $sequence = 0;

    public static function device(): Device
    {
        $n = ++self::$sequence;
        $company = Company::create(['name_en' => "Acme Trading {$n}"]);
        $vat = VatRegistration::create([
            'company_id' => $company->id,
            'vat_number' => '3' . str_pad((string) $n, 13, '0', STR_PAD_LEFT) . '3',
            'legal_name' => "Acme Trading {$n} LLC",
        ]);

        return Device::create([
            'company_id' => $company->id,
            'vat_registration_id' => $vat->id,
            'device_uuid' => (string) Str::uuid(),
            'name' => 'POS-01',
            'serial_number' => '1-CodingTriplets|2-laravel-zatca|3-0001',
            'invoice_type_flags' => '1100',
            'environment' => Environment::Sandbox,
            'lifecycle_status' => DeviceLifecycleStatus::Active,
        ]);
    }

    public static function certificate(Device $device): Certificate
    {
        $key = DeviceKey::create([
            'device_id' => $device->id, 'environment' => Environment::Sandbox, 'algorithm' => 'secp256k1',
            'encrypted_private_key' => 'envelope', 'public_key' => 'pub', 'csr' => 'csr',
            'key_fingerprint' => str_repeat('a', 64), 'vault_key_id' => 'k1',
        ]);

        return Certificate::create([
            'device_key_id' => $key->id, 'device_id' => $device->id, 'environment' => Environment::Sandbox,
            'type' => CertificateType::Production, 'version' => 1, 'certificate' => 'cert',
            'encrypted_secret' => 'envelope', 'vault_key_id' => 'k1', 'status' => CertificateStatus::Active,
        ]);
    }

    public static function invoice(Device $device, Workflow $workflow = Workflow::B2C, string $externalId = 'EXT-1'): Invoice
    {
        $invoice = Invoice::create([
            'public_id' => (string) Str::ulid(),
            'company_id' => $device->company_id,
            'vat_registration_id' => $device->vat_registration_id,
            'device_id' => $device->id,
            'environment' => Environment::Sandbox,
            'workflow' => $workflow,
            'external_invoice_id' => $externalId,
            'invoice_number' => 'INV-' . $externalId,
            'document_type' => DocumentType::Invoice,
            'subtype_flags' => $workflow->subtypePrefix() . '00000',
            'invoice_date' => '2026-11-02',
            'processing_status' => ProcessingStatus::Received,
            'validation_status' => ValidationStatus::Pending,
            'submission_status' => SubmissionStatus::NotStarted,
            'reporting_status' => $workflow === Workflow::B2C ? ReportingStatus::Pending : ReportingStatus::NotApplicable,
            'clearance_status' => $workflow === Workflow::B2B ? ClearanceStatus::Pending : ClearanceStatus::NotApplicable,
        ]);

        $version = InvoiceVersion::create([
            'invoice_id' => $invoice->id, 'version' => 1, 'kind' => VersionKind::Original,
            'payload' => ['id' => $externalId], 'payload_sha256' => hash('sha256', $externalId),
        ]);
        $invoice->update(['current_version_id' => $version->id]);

        return $invoice;
    }

    public static function issuance(Invoice $invoice, Certificate $certificate, int $icv, int $sequence = 1): InvoiceIssuance
    {
        return InvoiceIssuance::create([
            'invoice_id' => $invoice->id, 'sequence' => $sequence, 'version_id' => $invoice->current_version_id,
            'uuid' => (string) Str::uuid(), 'device_id' => $invoice->device_id, 'environment' => Environment::Sandbox,
            'icv' => $icv, 'previous_invoice_hash' => 'pih-' . $icv, 'invoice_hash' => 'hash-' . $icv,
            'issue_date' => '2026-11-02', 'issue_time' => '10:00:00', 'certificate_id' => $certificate->id,
            'reason' => IssuanceReason::Initial, 'status' => IssuanceStatus::Issued, 'issued_at' => now(),
        ]);
    }
}
