<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Database\Models;

use CodingTriplets\Zatca\Laravel\Database\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Table: {prefix}error_groups */
class ErrorGroup extends ZatcaModel
{
    use BelongsToTenant;

    protected string $baseTable = 'error_groups';

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function errors(): HasMany
    {
        return $this->hasMany(ZatcaError::class, 'group_id');
    }
}
