<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Invoice\Shared\DTOs;

use CodingTriplets\Zatca\Core\Invoice\Shared\Calculation\Amount;

/** Document-level allowance (BG-20) or charge (BG-21). Amount excludes VAT. */
final class AllowanceCharge
{
    public function __construct(
        public readonly bool $isCharge,
        public readonly Amount $amount,
        public readonly string $reason,
        public readonly ?string $reasonCode,
        public readonly VatCategory $vat,
    ) {
    }
}
