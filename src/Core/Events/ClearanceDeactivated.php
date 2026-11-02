<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Events;

/** B2B: ZATCA answered 303 (clearance switched off). The standard invoice is reported instead (or parked, see config) ⚠. */
final class ClearanceDeactivated
{
    public function __construct(
        public readonly int $invoiceId,
        public readonly string $publicId,
        public readonly int $issuanceId,
    ) {
    }
}
