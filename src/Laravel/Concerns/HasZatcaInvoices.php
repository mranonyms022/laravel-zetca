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
        return $this->morphMany(Invoice::class, 'source', 'source_type', 'source_id', 'zatca_source_key');
    }

    public function zatcaInvoice(): MorphOne
    {
        return $this->morphOne(Invoice::class, 'source', 'source_type', 'source_id', 'zatca_source_key')->latestOfMany('id');
    }

    /**
     * `source_id` is a string column (it also holds UUIDs and other non-numeric IDs). Comparing it with an
     * integer key fails on PostgreSQL (varchar = integer), so the relations always use the key as a string.
     */
    public function getZatcaSourceKeyAttribute(): ?string
    {
        $key = $this->getKey();

        return $key === null ? null : (string) $key;
    }
}
