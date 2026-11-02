<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Invoice\Shared\DTOs;

use CodingTriplets\Zatca\Core\Invoice\Shared\Calculation\Amount;

/**
 * Document-level allowance (BG-20) or charge (BG-21). Amount excludes VAT.
 * With a percentage, amount = base × percent / 100 (BR-KSA-EN16931-03); base and percent come together.
 */
final class AllowanceCharge
{
    public function __construct(
        public readonly bool $isCharge,
        public readonly Amount $amount,
        public readonly string $reason,
        public readonly ?string $reasonCode,
        public readonly VatCategory $vat,
        public readonly ?Amount $percent = null,       // BT-94 / BT-101
        public readonly ?Amount $baseAmount = null,    // BT-93 / BT-100
    ) {
    }
}
