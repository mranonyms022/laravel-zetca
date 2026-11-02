<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Database\Models;

use CodingTriplets\Zatca\Laravel\Database\Models\Concerns\AppendOnly;
use CodingTriplets\Zatca\Laravel\Database\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Table: {prefix}invoice_lines (append-only): calculated invoice lines as written in the signed XML, one set per issuance. */
class InvoiceLine extends ZatcaModel
{
    use AppendOnly, BelongsToTenant;

    public $timestamps = false;

    protected string $baseTable = 'invoice_lines';

    protected $casts = [
        'vat_rate' => 'decimal:2',
        'created_at' => 'datetime',
        'line_no' => 'integer',
        'quantity' => 'decimal:6',
        'unit_price' => 'decimal:6',
        'unit_discount' => 'decimal:6',
        'line_discount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'amount_incl_vat' => 'decimal:2',
        'discounts' => 'array',
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
