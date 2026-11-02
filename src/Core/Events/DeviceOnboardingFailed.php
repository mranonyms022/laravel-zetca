<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Events;

/** Dispatched after the surrounding transaction commits. Contains no secrets. */
final class DeviceOnboardingFailed
{
    public function __construct(
        public readonly int $deviceId,
        public readonly int $onboardingId,
        public readonly string $step,
        public readonly string $reason,
    ) {
    }
}
