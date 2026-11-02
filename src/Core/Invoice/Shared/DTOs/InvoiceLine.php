<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Invoice\Shared\DTOs;

use CodingTriplets\Zatca\Core\Invoice\Shared\Calculation\Amount;

/** Invoice line (BG-25). Prices exclude VAT. `discount` is a per-unit price discount (BT-147). */
final class InvoiceLine
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly Amount $quantity,
        public readonly string $unitCode,
        public readonly Amount $unitPrice,
        public readonly Amount $discount,
        public readonly VatCategory $vat,
    ) {
    }

    /** BT-146 net price = gross price − discount. */
    public function netPrice(): Amount
    {
        return $this->unitPrice->minus($this->discount);
    }

    /** BT-131 line net amount = quantity × net price, rounded to 2 decimals. */
    public function netAmount(): Amount
    {
        return $this->quantity->times($this->netPrice())->round(2);
    }

    /** KSA-11 line VAT amount. */
    public function vatAmount(): Amount
    {
        return $this->netAmount()->times($this->vat->rate)->times('0.01')->round(2);
    }
}
