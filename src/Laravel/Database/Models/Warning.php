<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Database\Models;

use CodingTriplets\Zatca\Laravel\Database\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Table: {prefix}warnings */
class Warning extends ZatcaModel
{
    use BelongsToTenant;

    protected string $baseTable = 'warnings';

    protected $casts = [
        'workflow' => \CodingTriplets\Zatca\Core\Domain\Enums\Workflow::class,
        'details' => 'array',
        'is_acknowledged' => 'boolean',
        'acknowledged_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }
}
