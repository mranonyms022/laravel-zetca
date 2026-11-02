<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Invoice\Shared\Signing;

/** Signed XML plus the values stored alongside it. The XML must never be modified afterwards. */
final class SignedInvoice
{
    public function __construct(
        public readonly string $xml,
        public readonly string $hash,
        public readonly string $signature,
        public readonly string $qrCode,
        public readonly string $signingTime,
    ) {
    }
}
