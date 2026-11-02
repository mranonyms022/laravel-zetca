<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Database\Models;

use CodingTriplets\Zatca\Laravel\Database\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Table: {prefix}companies */
class Company extends ZatcaModel
{
    use BelongsToTenant, SoftDeletes;

    protected string $baseTable = 'companies';

    /** Soft-delete column (dateTime, see ZatcaMigration). */
    public const DELETED_AT = 'deleted_at';

    protected $casts = [
        'address' => 'array',
        'settings' => 'array',
    ];

    public function vatRegistrations(): HasMany
    {
        return $this->hasMany(VatRegistration::class, 'company_id');
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class, 'company_id');
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class, 'company_id');
    }
}
