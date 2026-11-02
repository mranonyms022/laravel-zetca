<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Database\Models;

use CodingTriplets\Zatca\Laravel\Database\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Table: {prefix}chain_entries (append-only) */
class ChainEntry extends ZatcaModel
{
    use AppendOnly;

    public $timestamps = false;

    protected string $baseTable = 'chain_entries';

    protected $casts = [
        'environment' => \CodingTriplets\Zatca\Core\Domain\Enums\Environment::class,
        'icv' => 'integer',
        'committed_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    public function issuance(): BelongsTo
    {
        return $this->belongsTo(InvoiceIssuance::class, 'issuance_id');
    }
}
