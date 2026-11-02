<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Tests\Unit\Invoice;

use CodingTriplets\Zatca\Core\Domain\ValueObjects\InvoiceHash;
use CodingTriplets\Zatca\Core\Invoice\B2B\Generator\StandardInvoiceGenerator;
use CodingTriplets\Zatca\Core\Invoice\B2C\Generator\SimplifiedInvoiceGenerator;
use CodingTriplets\Zatca\Core\Invoice\Shared\Chain\ChainPosition;
use CodingTriplets\Zatca\Core\Invoice\Shared\QRCode\QrCode;
use CodingTriplets\Zatca\Core\Invoice\Shared\Signing\SignatureVerifier;
use CodingTriplets\Zatca\Core\Invoice\Shared\Validation\InvoiceValidationException;
use PHPUnit\Framework\TestCase;

final class InvoiceGenerationTest extends TestCase
{
    private const UUID = '3cf5ee18-ee25-44ea-a444-2c37ba7f28be';

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(InvoiceTestData::NOW, new \DateTimeZone('Asia/Riyadh'));
    }

    public function test_simplified_invoice_is_signed_and_verifiable(): void
    {
        $g = (new SimplifiedInvoiceGenerator())->generate(InvoiceTestData::b2c(), ChainPosition::first(), self::UUID, InvoiceTestData::credentials(), $this->now());

        $this->assertSame([], (new SignatureVerifier())->verify($g->xml()));
        $this->assertStringContainsString('<cbc:InvoiceTypeCode name="0200000">388</cbc:InvoiceTypeCode>', $g->xml());
        $this->assertStringContainsString('<cbc:UUID>1</cbc:UUID>', $g->xml());
        $this->assertStringContainsString(InvoiceHash::GENESIS, $g->xml());

        $qr = QrCode::decode($g->qrCode());
        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8, 9], array_keys($qr));
        $this->assertSame('Maximum Speed Tech Supply LTD', $qr[1]);
        $this->assertSame('2026-11-01T10:00:00', $qr[3]);
        $this->assertSame('227.36', $qr[4]);   // 197.70 + round(197.70 × 15%, 2) = 197.70 + 29.66
        $this->assertSame('29.66', $qr[5]);
        $this->assertSame($g->hash(), $qr[6]);
    }

    public function test_standard_credit_note_chains_to_previous_hash(): void
    {
        $first = (new SimplifiedInvoiceGenerator())->generate(InvoiceTestData::b2c(), ChainPosition::first(), self::UUID, InvoiceTestData::credentials(), $this->now());
        $note = (new StandardInvoiceGenerator())->generate(
            InvoiceTestData::b2b(['type' => 'credit_note', 'billing_reference' => 'SME00002', 'reason' => 'Returned items']),
            new ChainPosition(2, InvoiceHash::fromBase64($first->hash())),
            '1f2a2c9e-3b8d-4c41-9f0a-2a3c4d5e6f70',
            InvoiceTestData::credentials(),
            $this->now(),
        );

        $this->assertSame([], (new SignatureVerifier())->verify($note->xml()));
        $this->assertStringContainsString('<cbc:InvoiceTypeCode name="0100000">381</cbc:InvoiceTypeCode>', $note->xml());
        $this->assertStringContainsString($first->hash(), $note->xml());
        $this->assertStringContainsString('<cbc:InstructionNote>Returned items</cbc:InstructionNote>', $note->xml());
        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8], array_keys(QrCode::decode($note->qrCode())));
    }

    public function test_root_elements_follow_ubl_order(): void
    {
        $g = (new SimplifiedInvoiceGenerator())->generate(InvoiceTestData::b2c(['buyer' => ['name' => 'Walk-in']]), ChainPosition::first(), self::UUID, InvoiceTestData::credentials(), $this->now());
        $xml = $g->xml();

        // Element order of the root (UBL 2.1 sequence, as in the SDK samples).
        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $names = [];
        foreach ($dom->documentElement->childNodes as $node) {
            if ($node instanceof \DOMElement) {
                $names[] = $node->nodeName;
            }
        }
        $order = array_values(array_unique($names));
        $this->assertSame([
            'ext:UBLExtensions', 'cbc:ProfileID', 'cbc:ID', 'cbc:UUID', 'cbc:IssueDate', 'cbc:IssueTime', 'cbc:InvoiceTypeCode',
            'cbc:DocumentCurrencyCode', 'cbc:TaxCurrencyCode', 'cac:AdditionalDocumentReference',
            'cac:Signature', 'cac:AccountingSupplierParty', 'cac:AccountingCustomerParty', 'cac:PaymentMeans', 'cac:TaxTotal',
            'cac:LegalMonetaryTotal', 'cac:InvoiceLine',
        ], $order);
    }

    public function test_workflows_are_never_converted(): void
    {
        try {
            (new StandardInvoiceGenerator())->generate(InvoiceTestData::b2c(), ChainPosition::first(), self::UUID, InvoiceTestData::credentials(), $this->now());
            $this->fail('B2C payload accepted by the B2B generator.');
        } catch (InvoiceValidationException $e) {
            $this->assertTrue($e->result->hasError('WORKFLOW'));
        }

        $this->assertTrue((new SimplifiedInvoiceGenerator())->validate(InvoiceTestData::b2b(), $this->now())->hasError('WORKFLOW'));
    }

    public function test_xml_special_characters_are_escaped(): void
    {
        $payload = InvoiceTestData::b2c();
        $payload['lines'][0]['name'] = 'Fish & Chips <large> "special"';
        $g = (new SimplifiedInvoiceGenerator())->generate($payload, ChainPosition::first(), self::UUID, InvoiceTestData::credentials(), $this->now());

        $this->assertStringContainsString('Fish &amp; Chips &lt;large&gt; &quot;special&quot;', $g->xml());
        $this->assertSame([], (new SignatureVerifier())->verify($g->xml()));
    }

    public function test_tampering_is_detected(): void
    {
        $g = (new SimplifiedInvoiceGenerator())->generate(InvoiceTestData::b2c(), ChainPosition::first(), self::UUID, InvoiceTestData::credentials(), $this->now());
        $tampered = str_replace('<cbc:Name>Pen</cbc:Name>', '<cbc:Name>Pencil</cbc:Name>', $g->xml());

        $this->assertContains('Invoice hash does not match ds:DigestValue.', (new SignatureVerifier())->verify($tampered));
    }

    public function test_invalid_uuid_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new SimplifiedInvoiceGenerator())->generate(InvoiceTestData::b2c(), ChainPosition::first(), 'not-a-uuid', InvoiceTestData::credentials(), $this->now());
    }
}
