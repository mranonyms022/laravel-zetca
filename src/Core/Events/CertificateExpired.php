<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Events;

/** Dispatched after the surrounding transaction commits. Contains no secrets. */
final class CertificateExpired
{
    public function __construct(
        public readonly int $deviceId,
        public readonly int $certificateId,
    ) {
    }
}
