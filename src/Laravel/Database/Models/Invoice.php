<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Database\Models;

use CodingTriplets\Zatca\Laravel\Database\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Table: {prefix}invoices */
class Invoice extends ZatcaModel
{
    use BelongsToTenant, SoftDeletes;

    protected string $baseTable = 'invoices';

    /** Soft-delete column (dateTime, see ZatcaMigration). */
    public const DELETED_AT = 'deleted_at';

    protected $casts = [
        'environment' => \CodingTriplets\Zatca\Core\Domain\Enums\Environment::class,
        'workflow' => \CodingTriplets\Zatca\Core\Domain\Enums\Workflow::class,
        'document_type' => \CodingTriplets\Zatca\Core\Domain\Enums\DocumentType::class,
        'processing_status' => \CodingTriplets\Zatca\Core\Domain\Enums\ProcessingStatus::class,
        'validation_status' => \CodingTriplets\Zatca\Core\Domain\Enums\ValidationStatus::class,
        'submission_status' => \CodingTriplets\Zatca\Core\Domain\Enums\SubmissionStatus::class,
        'reporting_status' => \CodingTriplets\Zatca\Core\Domain\Enums\ReportingStatus::class,
        'clearance_status' => \CodingTriplets\Zatca\Core\Domain\Enums\ClearanceStatus::class,
        'invoice_date' => 'date',
        'first_issued_at' => 'datetime',
        'last_submitted_at' => 'datetime',
        'reported_at' => 'datetime',
        'cleared_at' => 'datetime',
        'reporting_deadline_at' => 'datetime',
        'next_retry_at' => 'datetime',
        'status_changed_at' => 'datetime',
        'approved_at' => 'datetime',
        'total_excl_vat' => 'decimal:2',
        'vat_total' => 'decimal:2',
        'total_incl_vat' => 'decimal:2',
        'requires_approval' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function vatRegistration(): BelongsTo
    {
        return $this->belongsTo(VatRegistration::class, 'vat_registration_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(InvoiceVersion::class, 'invoice_id');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(InvoiceVersion::class, 'current_version_id');
    }

    public function issuances(): HasMany
    {
        return $this->hasMany(InvoiceIssuance::class, 'invoice_id');
    }

    public function currentIssuance(): BelongsTo
    {
        return $this->belongsTo(InvoiceIssuance::class, 'current_issuance_id');
    }

    public function drafts(): HasMany
    {
        return $this->hasMany(InvoiceDraft::class, 'invoice_id');
    }

    public function billingReference(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'billing_reference_invoice_id');
    }

    public function adjustmentNotes(): HasMany
    {
        return $this->hasMany(Invoice::class, 'billing_reference_invoice_id');
    }

    public function processingLogs(): HasMany
    {
        return $this->hasMany(ProcessingLog::class, 'invoice_id');
    }

    public function errors(): HasMany
    {
        return $this->hasMany(ZatcaError::class, 'invoice_id');
    }

    public function warnings(): HasMany
    {
        return $this->hasMany(Warning::class, 'invoice_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(SubmissionAttempt::class, 'invoice_id');
    }
}
