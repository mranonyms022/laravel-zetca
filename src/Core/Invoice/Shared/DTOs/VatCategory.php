<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Invoice\Shared\DTOs;

use CodingTriplets\Zatca\Core\Invoice\Shared\Calculation\Amount;

/** VAT category code (UNCL5305 subset used in KSA), rate and exemption reason. */
final class VatCategory
{
    public const STANDARD = 'S';
    public const ZERO = 'Z';
    public const EXEMPT = 'E';
    public const OUT_OF_SCOPE = 'O';

    /** BR-KSA-CL-04 exemption reason codes per category (XML Implementation Standard). */
    public const REASON_CODES = [
        self::ZERO => ['VATEX-SA-32', 'VATEX-SA-33', 'VATEX-SA-34-1', 'VATEX-SA-34-2', 'VATEX-SA-34-3', 'VATEX-SA-34-4', 'VATEX-SA-34-5', 'VATEX-SA-35', 'VATEX-SA-36', 'VATEX-SA-EDU', 'VATEX-SA-HEA', 'VATEX-SA-MLTRY'],
        self::EXEMPT => ['VATEX-SA-29', 'VATEX-SA-29-7', 'VATEX-SA-30'],
        self::OUT_OF_SCOPE => ['VATEX-SA-OOS'],
    ];

    public function __construct(
        public readonly string $code,
        public readonly Amount $rate,
        public readonly ?string $reasonCode = null,
        public readonly ?string $reason = null,
    ) {
    }

    /** Grouping key for the VAT breakdown (BG-23): one subtotal per category, rate and reason. */
    public function key(): string
    {
        return $this->code . '|' . $this->rate->format(2) . '|' . ($this->reasonCode ?? '');
    }
}
