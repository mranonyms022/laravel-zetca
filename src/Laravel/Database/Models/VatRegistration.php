<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Database\Models;

use CodingTriplets\Zatca\Laravel\Database\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Table: {prefix}vat_registrations */
class VatRegistration extends ZatcaModel
{
    use BelongsToTenant;

    protected string $baseTable = 'vat_registrations';

    protected $casts = [
        'address' => 'array',
        'is_group' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class, 'vat_registration_id');
    }
}
