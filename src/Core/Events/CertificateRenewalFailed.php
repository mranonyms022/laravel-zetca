<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Events;

/** Dispatched after the surrounding transaction commits. Contains no secrets. */
final class CertificateRenewalFailed
{
    public function __construct(
        public readonly int $deviceId,
        public readonly int $renewalId,
        public readonly string $reason,
    ) {
    }
}
