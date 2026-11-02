<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Database\Models;

use CodingTriplets\Zatca\Laravel\Database\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Table: {prefix}invoice_artifacts (append-only) */
class InvoiceArtifact extends ZatcaModel
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected string $baseTable = 'invoice_artifacts';

    protected $casts = [
        'kind' => \CodingTriplets\Zatca\Core\Domain\Enums\ArtifactKind::class,
        'encrypted' => 'boolean',
        'bytes' => 'integer',
    ];

    public function issuance(): BelongsTo
    {
        return $this->belongsTo(InvoiceIssuance::class, 'issuance_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }
}
