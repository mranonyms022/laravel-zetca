<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Invoice\Shared\DTOs;

use CodingTriplets\Zatca\Core\Invoice\Shared\Calculation\Amount;

/**
 * Invoice line (BG-25). Prices exclude VAT.
 *  - `discount`: per-unit price discount (BT-147), written in cac:Price.
 *  - `allowances`: line allowances (BG-27, line totals), each subtracted from the line net amount (BT-131).
 */
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
        /** @var list<LineAllowance> */
        public readonly array $allowances = [],
    ) {
    }

    /** BT-146 net price = gross price − discount. */
    public function netPrice(): Amount
    {
        return $this->unitPrice->minus($this->discount);
    }

    /** Quantity × net price, rounded to 2 decimals (before line allowances). */
    public function grossAmount(): Amount
    {
        return $this->quantity->times($this->netPrice())->round(2);
    }

    /** Σ line allowance amounts (BT-136). */
    public function allowanceTotal(): Amount
    {
        $total = Amount::zero();
        foreach ($this->allowances as $allowance) {
            $total = $total->plus($allowance->amount->round(2));
        }

        return $total;
    }

    /** BT-131 line net amount = quantity × net price − line allowances, each part rounded separately. */
    public function netAmount(): Amount
    {
        return $this->grossAmount()->minus($this->allowanceTotal());
    }

    /** KSA-11 line VAT amount. */
    public function vatAmount(): Amount
    {
        return $this->netAmount()->times($this->vat->rate)->times('0.01')->round(2);
    }
}
