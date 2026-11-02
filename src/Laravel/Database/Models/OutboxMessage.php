<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Database\Models;

use CodingTriplets\Zatca\Laravel\Database\Models\Concerns\BelongsToTenant;

/** Table: {prefix}outbox */
class OutboxMessage extends ZatcaModel
{
    use BelongsToTenant;

    protected string $baseTable = 'outbox';

    protected $casts = [
        'payload' => 'array',
        'available_at' => 'datetime',
        'claimed_until' => 'datetime',
        'processed_at' => 'datetime',
        'dead_lettered_at' => 'datetime',
    ];
}
