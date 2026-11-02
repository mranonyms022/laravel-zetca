<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Events;

/** Dispatched after the surrounding transaction commits. Contains no secrets. */
final class CertificateExpiring
{
    public function __construct(
        public readonly int $deviceId,
        public readonly int $certificateId,
        public readonly int $daysLeft,
        public readonly \DateTimeImmutable $validUntil,
    ) {
    }
}
