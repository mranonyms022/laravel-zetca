<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Events;

/** Dispatched after the surrounding transaction commits. Contains no secrets. */
final class DeviceOnboarded
{
    public function __construct(
        public readonly int $deviceId,
        public readonly int $certificateId,
        public readonly string $environment,
        public readonly \DateTimeImmutable $validUntil,
    ) {
    }
}
