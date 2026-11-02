<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Database\Models;

use CodingTriplets\Zatca\Laravel\Database\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Table: {prefix}certificates (append-only) */
class Certificate extends ZatcaModel
{
    use AppendOnly;

    protected string $baseTable = 'certificates';

    /** @var list<string> columns that may change after insert */
    public array $appendOnlyMutable = ['status', 'activated_at', 'superseded_at'];

    /** Encrypted material is never serialized. */
    protected $hidden = ['encrypted_secret'];

    protected $casts = [
        'environment' => \CodingTriplets\Zatca\Core\Domain\Enums\Environment::class,
        'type' => \CodingTriplets\Zatca\Core\Domain\Enums\CertificateType::class,
        'status' => \CodingTriplets\Zatca\Core\Domain\Enums\CertificateStatus::class,
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'activated_at' => 'datetime',
        'superseded_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    public function deviceKey(): BelongsTo
    {
        return $this->belongsTo(DeviceKey::class, 'device_key_id');
    }
}
