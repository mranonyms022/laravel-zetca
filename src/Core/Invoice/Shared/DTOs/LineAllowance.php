<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Invoice\Shared\DTOs;

use CodingTriplets\Zatca\Core\Invoice\Shared\Calculation\Amount;

/**
 * Invoice line allowance (BG-27): a discount on the whole line, subtracted from the line net amount.
 * With a percentage, amount = base × percent / 100 (BR-KSA-EN16931-03); base and percent come together.
 */
final class LineAllowance
{
    public function __construct(
        public readonly Amount $amount,              // BT-136
        public readonly string $reason,              // BT-139
        public readonly ?string $reasonCode = null,  // BT-140 (UNTDID 5189, e.g. 95 = discount)
        public readonly ?Amount $percent = null,     // BT-138
        public readonly ?Amount $baseAmount = null,  // BT-137
    ) {
    }
}
