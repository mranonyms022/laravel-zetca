<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Database\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Table: {prefix}invoice_counters */
class InvoiceCounter extends ZatcaModel
{
    protected string $baseTable = 'invoice_counters';

    protected $casts = [
        'environment' => \CodingTriplets\Zatca\Core\Domain\Enums\Environment::class,
        'last_icv' => 'integer',
        'lock_version' => 'integer',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }
}
