<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Invoice\Shared\Calculation;

use CodingTriplets\Zatca\Core\Invoice\Shared\DTOs\InvoiceDocument;
use CodingTriplets\Zatca\Core\Invoice\Shared\DTOs\VatCategory;

/**
 * Document totals (BG-22) and VAT breakdown (BG-23), computed exactly:
 *  - one subtotal per (category, rate, exemption reason): always present, also for single-line invoices
 *    (prevents BR-S-01/BR-Z-01/BR-E-01/BR-O-01 failures, cf. zatca-xml-js issue #8);
 *  - subtotal VAT = round(taxable × rate, 2) (BR-S-09 / BR-CO-17);
 *  - total VAT (BT-110) = Σ subtotal VAT (BR-CO-14).
 */
final class InvoiceTotals
{
    /**
     * @param list<array{category: VatCategory, taxable: Amount, tax: Amount}> $subtotals
     */
    private function __construct(
        public readonly Amount $lineExtension,
        public readonly Amount $allowanceTotal,
        public readonly Amount $chargeTotal,
        public readonly Amount $taxExclusive,
        public readonly Amount $taxTotal,
        public readonly Amount $taxInclusive,
        public readonly Amount $prepaid,
        public readonly Amount $payable,
        public readonly array $subtotals,
    ) {
    }

    public static function calculate(InvoiceDocument $document): self
    {
        $lineExtension = Amount::zero();
        $allowances = Amount::zero();
        $charges = Amount::zero();
        /** @var array<string, array{category: VatCategory, taxable: Amount}> $groups */
        $groups = [];

        foreach ($document->lines as $line) {
            $net = $line->netAmount();
            $lineExtension = $lineExtension->plus($net);
            $groups[$line->vat->key()] ??= ['category' => $line->vat, 'taxable' => Amount::zero()];
            $groups[$line->vat->key()]['taxable'] = $groups[$line->vat->key()]['taxable']->plus($net);
        }

        foreach ($document->allowanceCharges as $ac) {
            $amount = $ac->amount->round(2);
            $groups[$ac->vat->key()] ??= ['category' => $ac->vat, 'taxable' => Amount::zero()];
            if ($ac->isCharge) {
                $charges = $charges->plus($amount);
                $groups[$ac->vat->key()]['taxable'] = $groups[$ac->vat->key()]['taxable']->plus($amount);
            } else {
                $allowances = $allowances->plus($amount);
                $groups[$ac->vat->key()]['taxable'] = $groups[$ac->vat->key()]['taxable']->minus($amount);
            }
        }

        $subtotals = [];
        $taxTotal = Amount::zero();
        foreach ($groups as $group) {
            $tax = $group['taxable']->times($group['category']->rate)->times('0.01')->round(2);
            $subtotals[] = ['category' => $group['category'], 'taxable' => $group['taxable']->round(2), 'tax' => $tax];
            $taxTotal = $taxTotal->plus($tax);
        }

        $taxExclusive = $lineExtension->minus($allowances)->plus($charges);
        $taxInclusive = $taxExclusive->plus($taxTotal);
        $prepaid = $document->prepaidAmount->round(2);

        return new self(
            $lineExtension, $allowances, $charges, $taxExclusive, $taxTotal, $taxInclusive,
            $prepaid, $taxInclusive->minus($prepaid), $subtotals,
        );
    }
}
