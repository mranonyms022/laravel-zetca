<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Database\Models;

use CodingTriplets\Zatca\Laravel\Database\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Table: {prefix}invoice_versions (append-only) */
class InvoiceVersion extends ZatcaModel
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected string $baseTable = 'invoice_versions';

    /** @var list<string> columns that may change after insert */
    public array $appendOnlyMutable = ['issuance_id'];

    protected $casts = [
        'kind' => \CodingTriplets\Zatca\Core\Domain\Enums\VersionKind::class,
        'payload' => 'array',
        'diff' => 'array',
        'changed_fields' => 'array',
        'validation_result' => 'array',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function previousVersion(): BelongsTo
    {
        return $this->belongsTo(InvoiceVersion::class, 'previous_version_id');
    }

    public function issuance(): BelongsTo
    {
        return $this->belongsTo(InvoiceIssuance::class, 'issuance_id');
    }
}
