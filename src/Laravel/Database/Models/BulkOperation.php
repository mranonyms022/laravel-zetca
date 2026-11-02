<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Database\Models;

use CodingTriplets\Zatca\Laravel\Database\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Table: {prefix}bulk_operations */
class BulkOperation extends ZatcaModel
{
    use BelongsToTenant;

    protected string $baseTable = 'bulk_operations';

    protected $casts = [
        'filters' => 'array',
        'completed_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(BulkOperationItem::class, 'bulk_operation_id');
    }
}
