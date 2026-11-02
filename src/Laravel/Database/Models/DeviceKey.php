<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Database\Models;

use CodingTriplets\Zatca\Laravel\Database\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Table: {prefix}device_keys (append-only) */
class DeviceKey extends ZatcaModel
{
    use AppendOnly;

    protected string $baseTable = 'device_keys';

    /** Encrypted material is never serialized. */
    protected $hidden = ['encrypted_private_key'];

    protected $casts = [
        'environment' => \CodingTriplets\Zatca\Core\Domain\Enums\Environment::class,
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class, 'device_key_id');
    }
}
