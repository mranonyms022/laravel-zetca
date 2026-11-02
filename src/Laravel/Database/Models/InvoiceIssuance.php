<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Database\Models;

use CodingTriplets\Zatca\Laravel\Database\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Table: {prefix}invoice_issuances (append-only) */
class InvoiceIssuance extends ZatcaModel
{
    use AppendOnly;

    protected string $baseTable = 'invoice_issuances';

    /** @var list<string> columns that may change after insert */
    public array $appendOnlyMutable = ['status'];

    protected $casts = [
        'environment' => \CodingTriplets\Zatca\Core\Domain\Enums\Environment::class,
        'reason' => \CodingTriplets\Zatca\Core\Domain\Enums\IssuanceReason::class,
        'status' => \CodingTriplets\Zatca\Core\Domain\Enums\IssuanceStatus::class,
        'icv' => 'integer',
        'issue_date' => 'date',
        'signing_time' => 'datetime',
        'issued_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(InvoiceVersion::class, 'version_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    public function certificate(): BelongsTo
    {
        return $this->belongsTo(Certificate::class, 'certificate_id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(InvoiceIssuance::class, 'supersedes_issuance_id');
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(InvoiceArtifact::class, 'issuance_id');
    }

    public function chainEntry(): HasOne
    {
        return $this->hasOne(ChainEntry::class, 'issuance_id');
    }

    public function clearanceSubmission(): HasOne
    {
        return $this->hasOne(B2bClearanceSubmission::class, 'issuance_id');
    }

    public function reportingSubmission(): HasOne
    {
        return $this->hasOne(B2cReportingSubmission::class, 'issuance_id');
    }
}
