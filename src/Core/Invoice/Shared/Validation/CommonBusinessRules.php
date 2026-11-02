<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Invoice\Shared\Validation;

use CodingTriplets\Zatca\Core\Domain\Enums\SellerIdScheme;
use CodingTriplets\Zatca\Core\Domain\ValueObjects\VatNumber;
use CodingTriplets\Zatca\Core\Invoice\Shared\Calculation\InvoiceTotals;
use CodingTriplets\Zatca\Core\Invoice\Shared\DTOs\Address;
use CodingTriplets\Zatca\Core\Invoice\Shared\DTOs\InvoiceDocument;

/**
 * Rules shared by both workflows (subset of the ZATCA/EN16931 rules that can be checked before
 * signing). The authoritative full rule set is applied by ZATCA (compliance checks, clearance, reporting).
 */
final class CommonBusinessRules
{
    public function validate(InvoiceDocument $doc, ValidationResult $result, ?\DateTimeImmutable $now = null): void
    {
        $now ??= new \DateTimeImmutable('now', $doc->issuedAt->getTimezone());

        if ($doc->issuedAt > $now->modify('+5 minutes')) {
            $result->error('issued_at', 'BR-KSA-04', 'The issue date/time must not be in the future.');
        }

        // Seller (BR-KSA-39/40, BR-KSA-09, BR-KSA-37, BR-KSA-66, BR-KSA-08)
        if ($doc->seller->vatNumber === null || ! VatNumber::isValid($doc->seller->vatNumber)) {
            $result->error('seller.vat_number', 'BR-KSA-39', 'Seller VAT number must be 15 digits, starting and ending with 3.');
        }
        $this->saudiAddress($doc->seller->address, 'seller.address', $result, sellerRules: true);
        if ($doc->seller->idScheme !== null) {
            if (SellerIdScheme::tryFrom($doc->seller->idScheme) === null) {
                $result->error('seller.id_scheme', 'BR-KSA-08', 'Seller ID scheme must be one of CRN, MOM, MLS, 700, SAG, OTH.');
            }
            if ($doc->seller->idValue === null || preg_match('/^[a-zA-Z0-9]+$/', $doc->seller->idValue) !== 1) {
                $result->error('seller.id_value', 'BR-KSA-08', 'Seller ID must be alphanumeric.');
            }
        }

        // Credit / debit notes (BR-KSA-56, BR-KSA-17)
        if ($doc->type->requiresBillingReference()) {
            if ($doc->billingReferences === []) {
                $result->error('billing_reference', 'BR-KSA-56', 'Credit and debit notes must reference the original invoice (billing_reference).');
            }
            if ($doc->reason === null) {
                $result->error('reason', 'BR-KSA-17', 'Credit and debit notes must state the reason (reason).');
            }
        }

        // Supply dates (BR-KSA-35/36)
        if ($doc->supplyEndDate !== null && $doc->supplyDate === null) {
            $result->error('supply_date', 'BR-KSA-35', 'A supply end date requires a supply date.');
        }
        if ($doc->supplyEndDate !== null && $doc->supplyDate !== null && $doc->supplyEndDate < $doc->supplyDate) {
            $result->error('supply_end_date', 'BR-KSA-36', 'The supply end date must not be before the supply date.');
        }
        if (substr($doc->subtypeFlags, 5, 1) === '1' && ($doc->supplyDate === null || $doc->supplyEndDate === null)) {
            $result->error('supply_end_date', 'KSA-24', 'Summary invoices require supply_date and supply_end_date.');
        }

        // UNTDID 4461 codes (e.g. 10 cash, 30 credit, 42 bank account, 48 card, 1 not defined); ZATCA checks the list.
        if (preg_match('/^(\d{1,2}|ZZZ)$/', $doc->paymentMeansCode) !== 1) {
            $result->error('payment_means_code', 'BR-KSA-16', 'payment_means_code must be a UNTDID 4461 code, e.g. 10 (cash), 30 (credit), 42 (bank), 48 (card).');
        }

        $totals = InvoiceTotals::calculate($doc);
        if ($totals->taxExclusive->isNegative()) {
            $result->error('allowances', 'BR-CO-13', 'Document allowances exceed the invoice amount.');
        }
        if ($totals->payable->isNegative()) {
            $result->error('prepaid_amount', 'BR-CO-16', 'Prepaid amount exceeds the invoice total.');
        }
        foreach ($totals->subtotals as $i => $sub) {
            if ($sub['taxable']->isNegative()) {
                $result->error('allowances', 'BR-CO-13', "Taxable amount for VAT category {$sub['category']->code} is negative.");
            }
        }
    }

    public function saudiAddress(Address $a, string $path, ValidationResult $result, bool $sellerRules): void
    {
        $required = ['street' => $a->street, 'city' => $a->city, 'postal_code' => $a->postalCode];
        if ($a->country === 'SA') {
            $required += ['building_number' => $a->buildingNumber, 'district' => $a->district];
        }
        foreach ($required as $field => $value) {
            if ($value === null) {
                $result->error("{$path}.{$field}", $sellerRules ? 'BR-KSA-09' : 'BR-KSA-63', "Address {$field} is required.");
            }
        }

        if ($a->country === 'SA') {
            if ($a->buildingNumber !== null && preg_match('/^\d{4}$/', $a->buildingNumber) !== 1) {
                $result->error("{$path}.building_number", 'BR-KSA-37', 'Saudi building numbers have exactly 4 digits.');
            }
            if ($a->postalCode !== null && preg_match('/^\d{5}$/', $a->postalCode) !== 1) {
                $result->error("{$path}.postal_code", $sellerRules ? 'BR-KSA-66' : 'BR-KSA-67', 'Saudi postal codes have exactly 5 digits.');
            }
        }
        if (preg_match('/^[A-Z]{2}$/', $a->country) !== 1) {
            $result->error("{$path}.country", 'BR-CL-14', 'Country must be an ISO 3166 alpha-2 code.');
        }
    }
}
