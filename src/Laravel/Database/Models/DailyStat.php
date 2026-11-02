<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Database\Models;

use CodingTriplets\Zatca\Laravel\Database\Models\Concerns\BelongsToTenant;

/** Table: {prefix}stats_daily */
class DailyStat extends ZatcaModel
{
    use BelongsToTenant;

    protected string $baseTable = 'stats_daily';

    protected $casts = [
        'day' => 'date',
        'workflow' => \CodingTriplets\Zatca\Core\Domain\Enums\Workflow::class,
        'total_incl_vat' => 'decimal:2',
        'vat_total' => 'decimal:2',
    ];
}
