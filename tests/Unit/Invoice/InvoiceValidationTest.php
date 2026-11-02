<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Tests\Unit\Invoice;

use CodingTriplets\Zatca\Core\Domain\Enums\Workflow;
use CodingTriplets\Zatca\Core\Invoice\B2B\Generator\StandardInvoiceGenerator;
use CodingTriplets\Zatca\Core\Invoice\B2C\Generator\SimplifiedInvoiceGenerator;
use CodingTriplets\Zatca\Core\Invoice\Shared\Calculation\Amount;
use CodingTriplets\Zatca\Core\Invoice\Shared\Calculation\InvoiceTotals;
use CodingTriplets\Zatca\Core\Invoice\Shared\Validation\InvoicePayloadMapper;
use CodingTriplets\Zatca\Core\Invoice\Shared\Validation\ValidationResult;
use PHPUnit\Framework\TestCase;

final class InvoiceValidationTest extends TestCase
{
    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(InvoiceTestData::NOW, new \DateTimeZone('Asia/Riyadh'));
    }

    /** @param array<string, mixed> $payload @return list<string> */
    private function b2cErrors(array $payload): array
    {
        return array_column((new SimplifiedInvoiceGenerator())->validate($payload, $this->now())->errors(), 'code');
    }

    public function test_valid_payloads_pass(): void
    {
        $this->assertSame([], $this->b2cErrors(InvoiceTestData::b2c()));
        $this->assertSame([], array_column((new StandardInvoiceGenerator())->validate(InvoiceTestData::b2b(), $this->now())->errors(), 'code'));
    }

    public function test_missing_vat_category_is_inferred_only_for_15_percent(): void
    {
        $p = InvoiceTestData::b2c();
        unset($p['lines'][0]['vat_category']);
        $result = (new SimplifiedInvoiceGenerator())->validate($p, $this->now());
        $this->assertTrue($result->isValid());
        $this->assertSame('INFERRED', $result->warnings()[0]['code']);

        $p['lines'][0]['vat_rate'] = '0';
        $this->assertContains('BR-CL-18', $this->b2cErrors($p));
    }

    public function test_exempt_and_zero_rated_items_need_reason_codes(): void
    {
        $p = InvoiceTestData::b2c();
        $p['lines'][0] = ['name' => 'Medicine', 'quantity' => '1', 'unit_price' => '10', 'vat_category' => 'Z', 'vat_rate' => '0'];
        $this->assertContains('BR-KSA-CL-04', $this->b2cErrors($p));

        $p['lines'][0]['exemption_reason_code'] = 'VATEX-SA-29';   // exempt code on a zero-rated line
        $this->assertContains('BR-KSA-CL-04', $this->b2cErrors($p));

        $p['lines'][0]['exemption_reason_code'] = 'VATEX-SA-35';
        $this->assertContains('BR-KSA-69', $this->b2cErrors($p));

        $p['lines'][0]['exemption_reason'] = 'Medicines and medical equipment';
        $this->assertSame([], $this->b2cErrors($p));
    }

    public function test_rate_given_as_fraction_is_rejected(): void
    {
        $p = InvoiceTestData::b2c();
        $p['lines'][0]['vat_rate'] = 0.15;
        $this->assertContains('BR-S-05', $this->b2cErrors($p));
    }

    public function test_notes_require_reference_and_reason(): void
    {
        $errors = $this->b2cErrors(InvoiceTestData::b2c(['type' => 'credit_note']));
        $this->assertContains('BR-KSA-56', $errors);
        $this->assertContains('BR-KSA-17', $errors);
    }

    public function test_standard_invoice_requires_buyer_identification_and_address(): void
    {
        $gen = new StandardInvoiceGenerator();
        $this->assertTrue($gen->validate(InvoiceTestData::b2b(['buyer' => null]), $this->now())->hasError('BR-KSA-42'));

        $buyer = InvoiceTestData::buyer();
        unset($buyer['vat_number']);
        $this->assertTrue($gen->validate(InvoiceTestData::b2b(['buyer' => $buyer]), $this->now())->hasError('BR-KSA-81'));

        $buyer['identification'] = ['scheme' => 'NAT', 'value' => '1234567890'];
        $buyer['address']['postal_code'] = '123';
        $this->assertTrue($gen->validate(InvoiceTestData::b2b(['buyer' => $buyer]), $this->now())->hasError('BR-KSA-67'));
    }

    public function test_seller_rules(): void
    {
        $seller = InvoiceTestData::seller();
        $seller['vat_number'] = '123';
        $seller['address']['building_number'] = '12';
        $errors = $this->b2cErrors(InvoiceTestData::b2c(['seller' => $seller]));

        $this->assertContains('BR-KSA-39', $errors);
        $this->assertContains('BR-KSA-37', $errors);
    }

    public function test_prepaid_amount_is_rejected_until_prepayment_lines_are_supported(): void
    {
        $this->assertContains('UNSUPPORTED', $this->b2cErrors(InvoiceTestData::b2c(['prepaid_amount' => '10.00'])));
    }

    public function test_future_issue_date_is_rejected(): void
    {
        $this->assertContains('BR-KSA-04', $this->b2cErrors(InvoiceTestData::b2c(['issued_at' => '2026-11-02T12:00:00'])));
    }

    public function test_errors_are_collected_not_short_circuited(): void
    {
        $errors = $this->b2cErrors(['workflow' => 'b2c', 'lines' => [['quantity' => 'abc']]]);

        $this->assertContains('BR-02', $errors);
        $this->assertContains('BR-25', $errors);
        $this->assertContains('FORMAT', $errors);
        $this->assertContains('BR-06', $errors);
    }

    public function test_totals_round_half_up_per_vat_group(): void
    {
        $p = InvoiceTestData::b2c([
            'lines' => [
                ['name' => 'A', 'quantity' => '1', 'unit_price' => '0.10', 'vat_category' => 'S', 'vat_rate' => '15'],
                ['name' => 'B', 'quantity' => '1', 'unit_price' => '0.20', 'vat_category' => 'S', 'vat_rate' => '15'],
                ['name' => 'C', 'quantity' => '1', 'unit_price' => '5.00', 'vat_category' => 'O', 'vat_rate' => '0', 'exemption_reason_code' => 'VATEX-SA-OOS', 'exemption_reason' => 'Out of scope'],
            ],
            'allowances' => [['amount' => '0.10', 'reason' => 'discount', 'vat_category' => 'S', 'vat_rate' => '15']],
        ]);
        $doc = (new InvoicePayloadMapper())->map($p, new ValidationResult(), Workflow::B2C);
        $this->assertNotNull($doc);
        $t = InvoiceTotals::calculate($doc);

        $this->assertSame('5.30', $t->lineExtension->format());
        $this->assertSame('5.20', $t->taxExclusive->format());
        $this->assertCount(2, $t->subtotals);
        $this->assertSame('0.20', $t->subtotals[0]['taxable']->format());
        $this->assertSame('0.03', $t->subtotals[0]['tax']->format());   // 0.030
        $this->assertSame('0.00', $t->subtotals[1]['tax']->format());
        $this->assertSame('5.23', $t->taxInclusive->format());
    }

    public function test_amount_rounding(): void
    {
        $this->assertSame('29.51', Amount::of('29.505')->format());
        $this->assertSame('-29.51', Amount::of('-29.505')->format());
        $this->assertSame('0.30', Amount::fromInput(0.1 + 0.2)->format());
    }
}
