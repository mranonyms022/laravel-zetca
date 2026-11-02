<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Events;

/** B2C: the reissued document has a new QR code. Send the customer a new receipt if your process requires it ⚠. */
final class CustomerCopyReissued
{
    public function __construct(
        public readonly int $invoiceId,
        public readonly string $publicId,
        public readonly int $issuanceId,
        public readonly string $qr,
    ) {
    }
}
