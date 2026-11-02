<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Database\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Table: {prefix}device_renewals */
class DeviceRenewal extends ZatcaModel
{
    protected string $baseTable = 'device_renewals';

    protected $casts = [
        'environment' => \CodingTriplets\Zatca\Core\Domain\Enums\Environment::class,
        'completed_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    public function previousCertificate(): BelongsTo
    {
        return $this->belongsTo(Certificate::class, 'previous_certificate_id');
    }

    public function newCertificate(): BelongsTo
    {
        return $this->belongsTo(Certificate::class, 'new_certificate_id');
    }
}
