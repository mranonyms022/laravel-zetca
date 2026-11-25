<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Database\Models;

use CodingTriplets\Zatca\Laravel\Database\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Table: {prefix}invoice_artifacts (append-only). The XML is in `content` (database driver) or on `disk`/`path`. */
class InvoiceArtifact extends ZatcaModel
{
    use AppendOnly;

    public const UPDATED_AT = null;

    /** Every column except the (possibly large) XML `content`, for listings. */
    public const LIST_COLUMNS = ['id', 'issuance_id', 'invoice_id', 'kind', 'disk', 'path', 'sha256', 'bytes', 'encrypted', 'mime', 'encoding', 'created_at'];

    /** The stored XML is read through Storage\InvoiceArtifacts (verified), never serialized with the row. */
    protected $hidden = ['content'];

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
