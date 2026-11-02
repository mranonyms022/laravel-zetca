<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Invoice\Shared\Xml;

use CodingTriplets\Zatca\Core\Invoice\Shared\Calculation\Amount;
use CodingTriplets\Zatca\Core\Invoice\Shared\Calculation\InvoiceTotals;
use CodingTriplets\Zatca\Core\Invoice\Shared\DTOs\Address;
use CodingTriplets\Zatca\Core\Invoice\Shared\DTOs\InvoiceDocument;
use CodingTriplets\Zatca\Core\Invoice\Shared\DTOs\Party;
use CodingTriplets\Zatca\Core\Invoice\Shared\DTOs\VatCategory;

/**
 * Writes the UBL 2.1 invoice in ZATCA's layout (element order and indentation follow the official
 * SDK samples). The output already contains the signature/QR placeholders: the invoice hash is
 * computed on this final layout, so inserting signature values later cannot change it.
 */
final class UblInvoiceWriter
{
    public const NS_INVOICE = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';
    public const NS_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
    public const NS_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';
    public const NS_EXT = 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2';

    public const QR_PLACEHOLDER = 'SET_QR_CODE_DATA';

    private string $out = '';

    public function write(InvoiceDocument $doc, InvoiceTotals $totals, string $uuid, int $icv, string $previousHash, bool $withSignatureBlock = true): string
    {
        $this->out = '';
        $cur = $doc->currency;

        $this->raw('<?xml version="1.0" encoding="UTF-8"?>' . "\n");
        $this->raw('<Invoice xmlns="' . self::NS_INVOICE . '" xmlns:cac="' . self::NS_CAC . '" xmlns:cbc="' . self::NS_CBC . '" xmlns:ext="' . self::NS_EXT . '">');
        if ($withSignatureBlock) {
            $this->raw(SignatureTemplate::UBL_EXTENSIONS);
        }
        $this->raw("\n");

        $this->el(1, 'cbc:ProfileID', 'reporting:1.0');
        $this->el(1, 'cbc:ID', $doc->invoiceNumber);
        $this->el(1, 'cbc:UUID', $uuid);
        $this->el(1, 'cbc:IssueDate', $doc->issuedAt->format('Y-m-d'));
        $this->el(1, 'cbc:IssueTime', $doc->issuedAt->format('H:i:s'));
        $this->el(1, 'cbc:InvoiceTypeCode', $doc->type->value, ['name' => $doc->subtypeFlags]);
        foreach ($doc->notes as $note) {
            $this->el(1, 'cbc:Note', $note);
        }
        $this->el(1, 'cbc:DocumentCurrencyCode', $cur);
        $this->el(1, 'cbc:TaxCurrencyCode', $cur);

        foreach ($doc->billingReferences as $ref) {
            $this->open(1, 'cac:BillingReference');
            $this->open(2, 'cac:InvoiceDocumentReference');
            $this->el(3, 'cbc:ID', $ref);
            $this->close(2, 'cac:InvoiceDocumentReference');
            $this->close(1, 'cac:BillingReference');
        }

        $this->open(1, 'cac:AdditionalDocumentReference');
        $this->el(2, 'cbc:ID', 'ICV');
        $this->el(2, 'cbc:UUID', (string) $icv);
        $this->close(1, 'cac:AdditionalDocumentReference');

        $this->open(1, 'cac:AdditionalDocumentReference');
        $this->el(2, 'cbc:ID', 'PIH');
        $this->open(2, 'cac:Attachment');
        $this->el(3, 'cbc:EmbeddedDocumentBinaryObject', $previousHash, ['mimeCode' => 'text/plain']);
        $this->close(2, 'cac:Attachment');
        $this->close(1, 'cac:AdditionalDocumentReference');

        if ($withSignatureBlock) {
            // Same layout as the SDK samples: QR reference and cac:Signature without surrounding whitespace.
            $this->open(1, 'cac:AdditionalDocumentReference');
            $this->el(2, 'cbc:ID', 'QR');
            $this->open(2, 'cac:Attachment');
            $this->el(3, 'cbc:EmbeddedDocumentBinaryObject', self::QR_PLACEHOLDER, ['mimeCode' => 'text/plain']);
            $this->close(2, 'cac:Attachment');
            $this->raw('</cac:AdditionalDocumentReference>');
            $this->raw(SignatureTemplate::CAC_SIGNATURE);
        }

        $this->party(1, 'cac:AccountingSupplierParty', $doc->seller, seller: true);
        if ($doc->buyer !== null) {
            $this->party(1, 'cac:AccountingCustomerParty', $doc->buyer, seller: false);
        } else {
            $this->open(1, 'cac:AccountingCustomerParty');
            $this->close(1, 'cac:AccountingCustomerParty');
        }

        if ($doc->supplyDate !== null) {
            $this->open(1, 'cac:Delivery');
            $this->el(2, 'cbc:ActualDeliveryDate', $doc->supplyDate->format('Y-m-d'));
            if ($doc->supplyEndDate !== null) {
                $this->el(2, 'cbc:LatestDeliveryDate', $doc->supplyEndDate->format('Y-m-d'));
            }
            $this->close(1, 'cac:Delivery');
        }

        $this->open(1, 'cac:PaymentMeans');
        $this->el(2, 'cbc:PaymentMeansCode', $doc->paymentMeansCode);
        if ($doc->reason !== null) {
            $this->el(2, 'cbc:InstructionNote', $doc->reason);
        }
        $this->close(1, 'cac:PaymentMeans');

        foreach ($doc->allowanceCharges as $ac) {
            $this->open(1, 'cac:AllowanceCharge');
            $this->el(2, 'cbc:ChargeIndicator', $ac->isCharge ? 'true' : 'false');
            if ($ac->reasonCode !== null) {
                $this->el(2, 'cbc:AllowanceChargeReasonCode', $ac->reasonCode);
            }
            $this->el(2, 'cbc:AllowanceChargeReason', $ac->reason);
            $this->money(2, 'cbc:Amount', $ac->amount, $cur);
            $this->taxCategory(2, 'cac:TaxCategory', $ac->vat, withReason: false);
            $this->close(1, 'cac:AllowanceCharge');
        }

        $this->open(1, 'cac:TaxTotal');
        $this->money(2, 'cbc:TaxAmount', $totals->taxTotal, $cur);
        $this->close(1, 'cac:TaxTotal');

        $this->open(1, 'cac:TaxTotal');
        $this->money(2, 'cbc:TaxAmount', $totals->taxTotal, $cur);
        foreach ($totals->subtotals as $sub) {
            $this->open(2, 'cac:TaxSubtotal');
            $this->money(3, 'cbc:TaxableAmount', $sub['taxable'], $cur);
            $this->money(3, 'cbc:TaxAmount', $sub['tax'], $cur);
            $this->taxCategory(3, 'cac:TaxCategory', $sub['category'], withReason: true);
            $this->close(2, 'cac:TaxSubtotal');
        }
        $this->close(1, 'cac:TaxTotal');

        $this->open(1, 'cac:LegalMonetaryTotal');
        $this->money(2, 'cbc:LineExtensionAmount', $totals->lineExtension, $cur);
        $this->money(2, 'cbc:TaxExclusiveAmount', $totals->taxExclusive, $cur);
        $this->money(2, 'cbc:TaxInclusiveAmount', $totals->taxInclusive, $cur);
        $this->money(2, 'cbc:AllowanceTotalAmount', $totals->allowanceTotal, $cur);
        if (! $totals->chargeTotal->isZero()) {
            $this->money(2, 'cbc:ChargeTotalAmount', $totals->chargeTotal, $cur);
        }
        $this->money(2, 'cbc:PrepaidAmount', $totals->prepaid, $cur);
        $this->money(2, 'cbc:PayableAmount', $totals->payable, $cur);
        $this->close(1, 'cac:LegalMonetaryTotal');

        foreach ($doc->lines as $line) {
            $net = $line->netAmount();
            $vat = $line->vatAmount();
            $this->open(1, 'cac:InvoiceLine');
            $this->el(2, 'cbc:ID', $line->id);
            $this->el(2, 'cbc:InvoicedQuantity', $line->quantity->format(6), ['unitCode' => $line->unitCode]);
            $this->money(2, 'cbc:LineExtensionAmount', $net, $cur);
            $this->open(2, 'cac:TaxTotal');
            $this->money(3, 'cbc:TaxAmount', $vat, $cur);
            $this->money(3, 'cbc:RoundingAmount', $net->plus($vat), $cur);
            $this->close(2, 'cac:TaxTotal');
            $this->open(2, 'cac:Item');
            $this->el(3, 'cbc:Name', $line->name);
            $this->taxCategory(3, 'cac:ClassifiedTaxCategory', $line->vat, withReason: false);
            $this->close(2, 'cac:Item');
            $this->open(2, 'cac:Price');
            $this->money(3, 'cbc:PriceAmount', $line->netPrice(), $cur, scale: max(2, min(6, $line->netPrice()->scale())));
            if (! $line->discount->isZero()) {
                $this->open(3, 'cac:AllowanceCharge');
                $this->el(4, 'cbc:ChargeIndicator', 'false');
                $this->el(4, 'cbc:AllowanceChargeReason', 'discount');
                $this->money(4, 'cbc:Amount', $line->discount, $cur, scale: max(2, min(6, $line->discount->scale())));
                $this->money(4, 'cbc:BaseAmount', $line->unitPrice, $cur, scale: max(2, min(6, $line->unitPrice->scale())));
                $this->close(3, 'cac:AllowanceCharge');
            }
            $this->close(2, 'cac:Price');
            $this->close(1, 'cac:InvoiceLine');
        }

        $this->raw('</Invoice>');

        return $this->out;
    }

    private function party(int $d, string $tag, Party $party, bool $seller): void
    {
        $this->open($d, $tag);
        $this->open($d + 1, 'cac:Party');
        if ($party->idScheme !== null && $party->idValue !== null) {
            $this->open($d + 2, 'cac:PartyIdentification');
            $this->el($d + 3, 'cbc:ID', $party->idValue, ['schemeID' => $party->idScheme]);
            $this->close($d + 2, 'cac:PartyIdentification');
        }
        $this->address($d + 2, $party->address);
        if ($party->vatNumber !== null) {
            $this->open($d + 2, 'cac:PartyTaxScheme');
            $this->el($d + 3, 'cbc:CompanyID', $party->vatNumber);
            $this->open($d + 3, 'cac:TaxScheme');
            $this->el($d + 4, 'cbc:ID', 'VAT');
            $this->close($d + 3, 'cac:TaxScheme');
            $this->close($d + 2, 'cac:PartyTaxScheme');
        } elseif (! $seller) {
            $this->open($d + 2, 'cac:PartyTaxScheme');
            $this->open($d + 3, 'cac:TaxScheme');
            $this->el($d + 4, 'cbc:ID', 'VAT');
            $this->close($d + 3, 'cac:TaxScheme');
            $this->close($d + 2, 'cac:PartyTaxScheme');
        }
        if ($party->name !== '') {
            $this->open($d + 2, 'cac:PartyLegalEntity');
            $this->el($d + 3, 'cbc:RegistrationName', $party->name);
            $this->close($d + 2, 'cac:PartyLegalEntity');
        }
        $this->close($d + 1, 'cac:Party');
        $this->close($d, $tag);
    }

    private function address(int $d, Address $a): void
    {
        $this->open($d, 'cac:PostalAddress');
        $this->optional($d + 1, 'cbc:StreetName', $a->street);
        $this->optional($d + 1, 'cbc:BuildingNumber', $a->buildingNumber);
        $this->optional($d + 1, 'cbc:PlotIdentification', $a->additionalNumber);
        $this->optional($d + 1, 'cbc:CitySubdivisionName', $a->district);
        $this->optional($d + 1, 'cbc:CityName', $a->city);
        $this->optional($d + 1, 'cbc:PostalZone', $a->postalCode);
        $this->optional($d + 1, 'cbc:CountrySubentity', $a->province);
        $this->open($d + 1, 'cac:Country');
        $this->el($d + 2, 'cbc:IdentificationCode', $a->country);
        $this->close($d + 1, 'cac:Country');
        $this->close($d, 'cac:PostalAddress');
    }

    private function taxCategory(int $d, string $tag, VatCategory $vat, bool $withReason): void
    {
        $this->open($d, $tag);
        $this->el($d + 1, 'cbc:ID', $vat->code);
        $this->el($d + 1, 'cbc:Percent', $vat->rate->format(2));
        if ($withReason && $vat->reasonCode !== null) {
            $this->el($d + 1, 'cbc:TaxExemptionReasonCode', $vat->reasonCode);
            $this->optional($d + 1, 'cbc:TaxExemptionReason', $vat->reason);
        }
        $this->open($d + 1, 'cac:TaxScheme');
        $this->el($d + 2, 'cbc:ID', 'VAT');
        $this->close($d + 1, 'cac:TaxScheme');
        $this->close($d, $tag);
    }

    private function money(int $d, string $tag, Amount $amount, string $currency, int $scale = 2): void
    {
        $this->el($d, $tag, $amount->format($scale), ['currencyID' => $currency]);
    }

    private function optional(int $d, string $tag, ?string $value): void
    {
        if ($value !== null && $value !== '') {
            $this->el($d, $tag, $value);
        }
    }

    /** @param array<string, string> $attributes */
    private function el(int $depth, string $tag, string $value, array $attributes = []): void
    {
        $attrs = '';
        foreach ($attributes as $k => $v) {
            $attrs .= ' ' . $k . '="' . self::escape($v) . '"';
        }
        $this->raw(str_repeat('    ', $depth) . "<{$tag}{$attrs}>" . self::escape($value) . "</{$tag}>\n");
    }

    private function open(int $depth, string $tag): void
    {
        $this->raw(str_repeat('    ', $depth) . "<{$tag}>\n");
    }

    private function close(int $depth, string $tag): void
    {
        $this->raw(str_repeat('    ', $depth) . "</{$tag}>\n");
    }

    private function raw(string $s): void
    {
        $this->out .= $s;
    }

    public static function escape(string $value): string
    {
        // Strip characters that are illegal in XML 1.0 instead of emitting an unparsable document.
        $value = (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value);

        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
