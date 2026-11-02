<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Domain\Exceptions;

/** The requested device operation is not allowed in the device's current state. */
final class DeviceStateException extends ZatcaException
{
    public static function notAllowed(int $deviceId, string $operation, string $status, string $hint = ''): self
    {
        return self::withContext(
            trim("Device #{$deviceId} cannot {$operation} while {$status}. {$hint}"),
            ['device_id' => $deviceId, 'operation' => $operation, 'status' => $status],
        );
    }
}
