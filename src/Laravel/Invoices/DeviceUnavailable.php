<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Invoices;

use CodingTriplets\Zatca\Core\Domain\Exceptions\ZatcaException;

/** The device cannot issue documents (not onboarded, suspended, expired, workflow not allowed, ...). No ICV was used. */
final class DeviceUnavailable extends ZatcaException
{
    public static function because(int $deviceId, string $reason): self
    {
        return self::withContext("Device #{$deviceId} cannot issue invoices: {$reason}", ['device_id' => $deviceId]);
    }
}
