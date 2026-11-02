<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Database\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Table: {prefix}device_onboardings */
class DeviceOnboarding extends ZatcaModel
{
    protected string $baseTable = 'device_onboardings';

    protected $casts = [
        'environment' => \CodingTriplets\Zatca\Core\Domain\Enums\Environment::class,
        'compliance_checks' => 'array',
        'completed_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }
}
