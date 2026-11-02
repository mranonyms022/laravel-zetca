<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Database\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Table: {prefix}invoice_drafts */
class InvoiceDraft extends ZatcaModel
{
    protected string $baseTable = 'invoice_drafts';

    protected $casts = [
        'payload' => 'array',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function baseVersion(): BelongsTo
    {
        return $this->belongsTo(InvoiceVersion::class, 'base_version_id');
    }
}
