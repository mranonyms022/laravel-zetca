<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Tests\Unit\Invoice;

use CodingTriplets\Zatca\Core\Invoice\B2C\Generator\SimplifiedInvoiceGenerator;
use CodingTriplets\Zatca\Core\Invoice\Shared\Calculation\InvoiceTotals;
use CodingTriplets\Zatca\Core\Invoice\Shared\Chain\ChainPosition;
use CodingTriplets\Zatca\Core\Invoice\Shared\Validation\InvoicePayloadMapper;
use CodingTriplets\Zatca\Core\Invoice\Shared\Validation\ValidationResult;
use PHPUnit\Framework\TestCase;

/** Line discounts (BG-27, zatca-xml-js shape), percentages and distributed header discounts. Shapes verified live 2026-11-02. */
final class DiscountTest extends TestCase
{
    private const SHIRT = ['name' => 'Shirt', 'quantity' => '3', 'unit_price' => '100.00', 'vat_category' => 'S', 'vat_rate' => '15'];

    private const MED = ['name' => 'Medicine', 'quantity' => '2', 'unit_price' => '25', 'vat_category' => 'Z', 'vat_rate' => '0',
        'exemption_reason_code' => 'VATEX-SA-35', 'exemption_reason' => 'Medicines and medical equipment'];

    public function test_several_line_discounts_reduce_the_line_net_amount(): void
    {
        [$doc, $totals] = $this->map(['lines' => [self::SHIRT + ['discounts' => [
            ['amount' => '20.00', 'reason' => 'Promotion'],
            ['amount' => '5.50', 'reason' => 'Voucher', 'reason_code' => '95'],
        ]]]]);

        $line = $doc->lines[0];
        self::assertSame('300.00', $line->grossAmount()->format(2));
        self::assertSame('25.50', $line->allowanceTotal()->format(2));
        self::assertSame('274.50', $line->netAmount()->format(2));
        self::assertSame('274.50', $totals->lineExtension->format(2));
        self::assertSame('41.18', $totals->taxTotal->format(2));
        self::assertSame('0.00', $totals->allowanceTotal->format(2), 'line allowances are not document allowances (BT-107)');
    }

    public function test_line_discount_percentage_is_calculated_from_the_line_amount(): void
    {
        [$doc] = $this->map(['lines' => [self::SHIRT + ['discount' => '7.25', 'discounts' => [['percent' => '12.5', 'reason' => 'Bundle']]]]]);

        $a = $doc->lines[0]->allowances[0];
        self::assertSame('278.25', $a->baseAmount->format(2), 'base = quantity × (price − unit discount)');
        self::assertSame('12.50', $a->percent->format(2));
        self::assertSame('34.78', $a->amount->format(2), '278.25 × 12.5 % = 34.78125 → 34.78');
        self::assertSame('243.47', $doc->lines[0]->netAmount()->format(2));
    }

    public function test_header_percentage_uses_the_net_amount_of_its_vat_group(): void
    {
        [$doc, $totals] = $this->map(['lines' => [self::SHIRT, self::MED],
            'allowances' => [['percent' => '5', 'reason' => 'Loyalty', 'vat_category' => 'S', 'vat_rate' => '15']]]);

        $ac = $doc->allowanceCharges[0];
        self::assertSame('300.00', $ac->baseAmount->format(2));
        self::assertSame('15.00', $ac->amount->format(2));
        self::assertSame('335.00', $totals->taxExclusive->format(2));
        self::assertSame('42.75', $totals->taxTotal->format(2), '(300 − 15) × 15 %');
    }

    public function test_distributed_header_amount_is_split_by_vat_group_without_losing_a_halala(): void
    {
        [$doc, $totals] = $this->map(['lines' => [self::SHIRT, self::MED],
            'allowances' => [['amount' => '33.33', 'reason' => 'Voucher', 'distribute' => true]]]);

        self::assertCount(2, $doc->allowanceCharges);
        self::assertSame('S', $doc->allowanceCharges[0]->vat->code);
        self::assertSame('28.57', $doc->allowanceCharges[0]->amount->format(2), '33.33 × 300 / 350');
        self::assertSame('4.76', $doc->allowanceCharges[1]->amount->format(2), 'remainder');
        self::assertSame('33.33', $totals->allowanceTotal->format(2));
    }

    public function test_distributed_header_percentage_creates_one_allowance_per_group_with_its_base(): void
    {
        [$doc] = $this->map(['lines' => [self::SHIRT, self::MED],
            'allowances' => [['percent' => '2.5', 'reason' => 'Early payment', 'distribute' => true]]]);

        self::assertSame(['7.50', '1.25'], array_map(static fn ($a) => $a->amount->format(2), $doc->allowanceCharges));
        self::assertSame(['300.00', '50.00'], array_map(static fn ($a) => $a->baseAmount->format(2), $doc->allowanceCharges));
    }

    public function test_invalid_discounts_are_reported_with_rule_ids(): void
    {
        $errors = $this->errors(['lines' => [self::SHIRT + ['discounts' => [
            ['percent' => '120', 'reason' => 'Too much'],
            ['amount' => '1.555', 'reason' => 'Three decimals'],
            ['amount' => '5'],
        ]]], 'allowances' => [['amount' => '10', 'reason' => 'x', 'distribute' => true, 'vat_category' => 'S', 'vat_rate' => '15']]]);

        self::assertSame('BR-KSA-DEC-01', $errors['lines.0.discounts.0.percent']);
        self::assertSame('BR-DEC-24', $errors['lines.0.discounts.1.amount']);
        self::assertSame('BR-42', $errors['lines.0.discounts.2.reason']);
        self::assertSame('FORMAT', $errors['allowances.0.distribute']);

        $exceed = $this->errors(['lines' => [self::SHIRT + ['discounts' => [['amount' => '300.01', 'reason' => 'All']]]]]);
        self::assertSame('BR-27', $exceed['lines.0.discounts']);

        $mismatch = $this->errors(['lines' => [self::SHIRT], 'allowances' => [['percent' => '10', 'amount' => '5', 'reason' => 'x', 'vat_category' => 'S', 'vat_rate' => '15']]]);
        self::assertSame('BR-KSA-EN16931-03', $mismatch['allowances.0.amount']);
    }

    public function test_xml_contains_line_allowances_before_tax_total_and_percentages(): void
    {
        $payload = InvoiceTestData::b2c(['lines' => [self::SHIRT + ['discounts' => [['percent' => '10', 'reason' => 'Member', 'reason_code' => '95']]]],
            'allowances' => [['percent' => '5', 'reason' => 'Loyalty', 'vat_category' => 'S', 'vat_rate' => '15']]]);
        $g = (new SimplifiedInvoiceGenerator())->generate($payload, ChainPosition::first(), '3cf5ee18-ee25-44ea-a444-2c37ba7f28be',
            InvoiceTestData::credentials(), new \DateTimeImmutable(InvoiceTestData::NOW, new \DateTimeZone('Asia/Riyadh')));

        $dom = new \DOMDocument();
        $dom->loadXML($g->xml());
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $xp->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $xp->registerNamespace('i', 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2');

        $line = $xp->query('/i:Invoice/cac:InvoiceLine/cac:AllowanceCharge')->item(0);
        self::assertNotNull($line);
        self::assertSame('cac:TaxTotal', $this->nextElement($line)?->nodeName, 'UBL order: AllowanceCharge before TaxTotal');
        self::assertSame('10.00', $xp->evaluate('string(cbc:MultiplierFactorNumeric)', $line));
        self::assertSame('30.00', $xp->evaluate('string(cbc:Amount)', $line));
        self::assertSame('300.00', $xp->evaluate('string(cbc:BaseAmount)', $line));
        self::assertSame('270.00', $xp->evaluate('string(/i:Invoice/cac:InvoiceLine/cbc:LineExtensionAmount)'));

        $doc = $xp->query('/i:Invoice/cac:AllowanceCharge')->item(0);
        self::assertSame('5.00', $xp->evaluate('string(cbc:MultiplierFactorNumeric)', $doc));
        self::assertSame('13.50', $xp->evaluate('string(cbc:Amount)', $doc));
        self::assertSame('270.00', $xp->evaluate('string(cbc:BaseAmount)', $doc));
    }

    /** @return array{0: \CodingTriplets\Zatca\Core\Invoice\Shared\DTOs\InvoiceDocument, 1: InvoiceTotals} */
    private function map(array $overrides): array
    {
        $result = new ValidationResult();
        $doc = (new InvoicePayloadMapper())->map(InvoiceTestData::b2c($overrides), $result);
        self::assertTrue($result->isValid(), json_encode($result->errors()));

        return [$doc, InvoiceTotals::calculate($doc)];
    }

    /** @return array<string, string> field => code */
    private function errors(array $overrides): array
    {
        $result = new ValidationResult();
        (new InvoicePayloadMapper())->map(InvoiceTestData::b2c($overrides), $result);

        return array_column($result->errors(), 'code', 'field');
    }

    private function nextElement(\DOMNode $node): ?\DOMNode
    {
        do {
            $node = $node->nextSibling;
        } while ($node !== null && $node->nodeType !== XML_ELEMENT_NODE);

        return $node;
    }
}
