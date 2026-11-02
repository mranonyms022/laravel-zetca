<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Database\Models;

use CodingTriplets\Zatca\Laravel\Database\Models\Concerns\BelongsToTenant;

/** Table: {prefix}settings */
class Setting extends ZatcaModel
{
    use BelongsToTenant;

    protected string $baseTable = 'settings';

    protected $casts = [
        'value' => 'array',
    ];
}
