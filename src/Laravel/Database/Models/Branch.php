<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Database\Models;

use CodingTriplets\Zatca\Laravel\Database\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Table: {prefix}branches */
class Branch extends ZatcaModel
{
    use BelongsToTenant, SoftDeletes;

    protected string $baseTable = 'branches';

    /** Soft-delete column (dateTime, see ZatcaMigration). */
    public const DELETED_AT = 'deleted_at';

    protected $casts = [
        'address_override' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class, 'branch_id');
    }
}
