<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Database\Models;

use CodingTriplets\Zatca\Laravel\Database\Models\Concerns\AppendOnly;
use CodingTriplets\Zatca\Laravel\Database\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Table: {prefix}invoice_vat_breakdown (append-only): VAT breakdown (BG-23) per issuance. */
class InvoiceVatBreakdown extends ZatcaModel
{
    use AppendOnly, BelongsToTenant;

    public $timestamps = false;

    protected string $baseTable = 'invoice_vat_breakdown';

    protected $casts = [
        'vat_rate' => 'decimal:2',
        'created_at' => 'datetime',
        'taxable_amount' => 'decimal:2',
        'vat_amount' => 'decimal:2',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function issuance(): BelongsTo
    {
        return $this->belongsTo(InvoiceIssuance::class, 'issuance_id');
    }
}
