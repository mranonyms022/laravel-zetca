<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Database\Models;

use CodingTriplets\Zatca\Laravel\Database\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Table: {prefix}devices */
class Device extends ZatcaModel
{
    use BelongsToTenant;

    protected string $baseTable = 'devices';

    protected $casts = [
        'environment' => \CodingTriplets\Zatca\Core\Domain\Enums\Environment::class,
        'lifecycle_status' => \CodingTriplets\Zatca\Core\Domain\Enums\DeviceLifecycleStatus::class,
        'certificate_expiry' => 'datetime',
        'submissions_paused_until' => 'datetime',
        'decommissioned_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function vatRegistration(): BelongsTo
    {
        return $this->belongsTo(VatRegistration::class, 'vat_registration_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function keys(): HasMany
    {
        return $this->hasMany(DeviceKey::class, 'device_id');
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class, 'device_id');
    }

    public function activeCertificate(): BelongsTo
    {
        return $this->belongsTo(Certificate::class, 'active_certificate_id');
    }

    public function counters(): HasMany
    {
        return $this->hasMany(InvoiceCounter::class, 'device_id');
    }

    public function onboardings(): HasMany
    {
        return $this->hasMany(DeviceOnboarding::class, 'device_id');
    }

    public function renewals(): HasMany
    {
        return $this->hasMany(DeviceRenewal::class, 'device_id');
    }
}
