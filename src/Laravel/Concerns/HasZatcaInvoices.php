<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Concerns;

use CodingTriplets\Zatca\Laravel\Database\Models\Invoice;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * For the host application's own invoice/order model (any table name):
 *
 *     class SalesBill extends Model { use HasZatcaInvoices; }
 *     Zatca::b2c()->issue($payload, ['device' => 1, 'source' => $bill]);
 *     $bill->zatcaInvoice;    // latest ZATCA invoice record (invoice or note)
 *     $bill->zatcaInvoices;   // all of them
 */
trait HasZatcaInvoices
{
    public function zatcaInvoices(): MorphMany
    {
        return $this->morphMany(Invoice::class, 'source', 'source_type', 'source_id');
    }

    public function zatcaInvoice(): MorphOne
    {
        return $this->morphOne(Invoice::class, 'source', 'source_type', 'source_id')->latestOfMany('id');
    }
}
