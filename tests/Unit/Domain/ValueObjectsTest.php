<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Tests\Unit\Domain;

use CodingTriplets\Zatca\Core\Domain\Enums\BuyerIdScheme;
use CodingTriplets\Zatca\Core\Domain\Enums\Workflow;
use CodingTriplets\Zatca\Core\Domain\Exceptions\InvalidValue;
use CodingTriplets\Zatca\Core\Domain\ValueObjects\BuyerIdentification;
use CodingTriplets\Zatca\Core\Domain\ValueObjects\EgsSerialNumber;
use CodingTriplets\Zatca\Core\Domain\ValueObjects\InvoiceHash;
use CodingTriplets\Zatca\Core\Domain\ValueObjects\InvoiceTypeFlags;
use CodingTriplets\Zatca\Core\Domain\ValueObjects\SubtypeFlags;
use CodingTriplets\Zatca\Core\Domain\ValueObjects\Tin;
use CodingTriplets\Zatca\Core\Domain\ValueObjects\VatNumber;
use PHPUnit\Framework\TestCase;

/**
 * Test names use the test_ prefix and no data providers so the suite runs unchanged on PHPUnit 9.5-12.
 */
final class ValueObjectsTest extends TestCase
{
    public function test_vat_number_accepts_15_digits_starting_and_ending_with_3(): void
    {
        $vat = VatNumber::fromString('399999999900003');

        self::assertSame('399999999900003', $vat->value);
        self::assertFalse($vat->isGroup());
    }

    public function test_vat_number_detects_vat_group_by_11th_digit(): void
    {
        self::assertTrue(VatNumber::fromString('300000000010003')->isGroup());
    }

    public function test_vat_number_rejects_invalid_values(): void
    {
        foreach (['399999999900004', '29999999990000', '3999999999000033', '3999999999OOO03', ''] as $bad) {
            self::assertFalse(VatNumber::isValid($bad), $bad);
        }

        $this->expectException(InvalidValue::class);
        VatNumber::fromString('123');
    }

    public function test_tin_requires_10_digits(): void
    {
        self::assertSame('1234567890', Tin::fromString('1234567890')->value);

        $this->expectException(InvalidValue::class);
        Tin::fromString('12345');
    }

    public function test_invoice_type_flags_map_tscz_positions(): void
    {
        $both = InvoiceTypeFlags::fromString('1100');
        self::assertTrue($both->supports(Workflow::B2B));
        self::assertTrue($both->supports(Workflow::B2C));

        $standardOnly = InvoiceTypeFlags::fromString('1000');
        self::assertTrue($standardOnly->supportsStandard());
        self::assertFalse($standardOnly->supportsSimplified());

        $simplifiedOnly = InvoiceTypeFlags::fromString('0100');
        self::assertSame([Workflow::B2C], $simplifiedOnly->workflows());

        self::assertSame('1100', InvoiceTypeFlags::forWorkflows(Workflow::B2C, Workflow::B2B)->value);
    }

    public function test_invoice_type_flags_reject_devices_supporting_nothing(): void
    {
        $this->expectException(InvalidValue::class);
        InvoiceTypeFlags::fromString('0011');
    }

    public function test_egs_serial_number_format(): void
    {
        $serial = EgsSerialNumber::compose('CodingTriplets', 'laravel-zatca-1.0', 'ed22f1d8-e6a2-1118-9b58-d9a8f11e445f');
        self::assertSame('1-CodingTriplets|2-laravel-zatca-1.0|3-ed22f1d8-e6a2-1118-9b58-d9a8f11e445f', $serial->value);

        self::assertSame('1-A|2-B|3-C', EgsSerialNumber::fromString('1-A|2-B|3-C')->value);

        $this->expectException(InvalidValue::class);
        EgsSerialNumber::fromString('A|B|C');
    }

    public function test_subtype_flags_resolve_workflow_and_flags(): void
    {
        $flags = SubtypeFlags::fromString('0200010');

        self::assertSame(Workflow::B2C, $flags->workflow());
        self::assertTrue($flags->isSummary());
        self::assertFalse($flags->isExport());
        self::assertSame('0100000', SubtypeFlags::plain(Workflow::B2B)->value);

        $this->expectException(InvalidValue::class);
        SubtypeFlags::fromString('0300000');
    }

    public function test_genesis_hash_is_base64_of_hex_sha256_of_zero(): void
    {
        self::assertSame(base64_encode(hash('sha256', '0')), InvoiceHash::GENESIS);
        self::assertTrue(InvoiceHash::genesis()->isGenesis());
    }

    public function test_invoice_hash_requires_32_byte_digest(): void
    {
        $hash = InvoiceHash::fromBinaryDigest(hash('sha256', '<Invoice/>', true));
        self::assertTrue($hash->equals(InvoiceHash::fromBase64($hash->value)));

        $this->expectException(InvalidValue::class);
        InvoiceHash::fromBase64(base64_encode('too-short'));
    }

    public function test_buyer_identification_validates_scheme_and_value(): void
    {
        $id = BuyerIdentification::of('nat', '1012345678');
        self::assertSame(BuyerIdScheme::Nat, $id->scheme);

        $this->expectException(InvalidValue::class);
        BuyerIdentification::of('NAT', '1012-345');
    }

    public function test_buyer_identification_rejects_unknown_scheme(): void
    {
        $this->expectException(InvalidValue::class);
        BuyerIdentification::of('DRV', '12345');
    }

    public function test_buyer_identification_precedence_follows_br_ksa_14_sequence(): void
    {
        $preferred = BuyerIdentification::preferred([
            BuyerIdentification::of('PAS', 'A1234567'),
            BuyerIdentification::of('NAT', '1012345678'),
            BuyerIdentification::of('CRN', '1010101010'),
        ]);

        self::assertNotNull($preferred);
        self::assertSame(BuyerIdScheme::Crn, $preferred->scheme);
        self::assertNull(BuyerIdentification::preferred([]));
    }
}
