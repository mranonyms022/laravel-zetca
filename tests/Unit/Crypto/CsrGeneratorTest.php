<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Tests\Unit\Crypto;

use CodingTriplets\Zatca\Core\Crypto\Der;
use CodingTriplets\Zatca\Core\Crypto\EcPrivateKey;
use CodingTriplets\Zatca\Core\Device\CsrGenerator;
use CodingTriplets\Zatca\Core\Device\CsrProfile;
use CodingTriplets\Zatca\Core\Domain\Enums\Environment;
use CodingTriplets\Zatca\Core\Domain\Exceptions\InvalidValue;
use CodingTriplets\Zatca\Core\Domain\ValueObjects\EgsSerialNumber;
use CodingTriplets\Zatca\Core\Domain\ValueObjects\InvoiceTypeFlags;
use CodingTriplets\Zatca\Core\Domain\ValueObjects\VatNumber;
use PHPUnit\Framework\TestCase;

final class CsrGeneratorTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../Fixtures/csr/';

    public function test_to_be_signed_part_is_byte_identical_to_openssl_reference(): void
    {
        $key = EcPrivateKey::fromPem((string) file_get_contents(self::FIXTURES . 'test-secp256k1-key.pem'));
        $reference = Der::pemToDer((string) file_get_contents(self::FIXTURES . 'openssl-reference.csr'));

        $mine = (new CsrGenerator())->certificationRequestInfo($this->profile(), $key);

        self::assertSame(bin2hex(self::firstElement($reference)), bin2hex($mine));
    }

    public function test_csr_signature_verifies_with_the_device_public_key(): void
    {
        $key = EcPrivateKey::generate();
        $der = Der::pemToDer((new CsrGenerator())->generate($this->profile(), $key));

        $tbs = self::firstElement($der);
        $signature = substr(self::elementAt($der, 2), 3); // BIT STRING: tag, len(<128), unused-bits byte

        self::assertSame(1, openssl_verify($tbs, $signature, $key->publicKeyPem(), OPENSSL_ALGO_SHA256));
        self::assertNotFalse(openssl_csr_get_public_key((new CsrGenerator())->generate($this->profile(), $key)));
    }

    public function test_arabic_names_are_encoded_as_utf8(): void
    {
        $key = EcPrivateKey::generate();
        $profile = $this->profile(organizationName: 'شركة توريد التكنولوجيا بأقصى سرعة المحدودة', industry: 'أنشطة توريدات');

        $subject = openssl_csr_get_subject((new CsrGenerator())->generate($profile, $key), false);

        self::assertSame('شركة توريد التكنولوجيا بأقصى سرعة المحدودة', $subject['organizationName']);
    }

    public function test_template_name_follows_environment(): void
    {
        $key = EcPrivateKey::generate();
        foreach ([Environment::Sandbox, Environment::Simulation, Environment::Production] as $env) {
            $tbs = (new CsrGenerator())->certificationRequestInfo($this->profile(environment: $env), $key);
            self::assertStringContainsString(Der::utf8String($env->certificateTemplateName()), $tbs);
        }
    }

    public function test_serial_attribute_can_be_switched_to_serial_number(): void
    {
        $key = EcPrivateKey::generate();
        $surname = (new CsrGenerator())->certificationRequestInfo($this->profile(), $key);
        $serial = (new CsrGenerator(CsrGenerator::SERIAL_ATTRIBUTE_SERIAL_NUMBER))->certificationRequestInfo($this->profile(), $key);

        self::assertStringContainsString(Der::oid('2.5.4.4'), $surname);
        self::assertStringContainsString(Der::oid('2.5.4.5'), $serial);
    }

    public function test_vat_group_requires_member_tin_as_organization_unit(): void
    {
        $this->expectException(InvalidValue::class);
        $this->profile(vat: '399999999910003', unit: 'Riyadh Branch');
    }

    public function test_vat_group_with_member_tin_is_accepted(): void
    {
        self::assertSame('3999999999', $this->profile(vat: '399999999910003', unit: '3999999999')->organizationUnit);
    }

    public function test_private_key_never_serializes_or_dumps(): void
    {
        $key = EcPrivateKey::generate();

        self::assertStringNotContainsString('PRIVATE KEY', print_r($key, true));
        $this->expectException(\LogicException::class);
        serialize($key);
    }

    public function test_der_primitives(): void
    {
        self::assertSame('020100', bin2hex(Der::integer(0)));
        self::assertSame('02020080', bin2hex(Der::integer(128)));
        self::assertSame('06092a864886f70d01090e', bin2hex(Der::oid('1.2.840.113549.1.9.14')));
        self::assertSame('8181', bin2hex(Der::length(129)));
        self::assertSame('820100', bin2hex(Der::length(256)));
    }

    private function profile(
        Environment $environment = Environment::Sandbox,
        string $vat = '399999999900003',
        string $unit = 'Riyadh Branch',
        string $organizationName = 'Maximum Speed Tech Supply LTD',
        string $industry = 'Supply activities',
    ): CsrProfile {
        return new CsrProfile(
            $environment,
            'TST-886431145-399999999900003',
            EgsSerialNumber::fromString('1-TST|2-TST|3-ed22f1d8-e6a2-1118-9b58-d9a8f11e445f'),
            VatNumber::fromString($vat),
            $unit,
            $organizationName,
            InvoiceTypeFlags::fromString('1100'),
            'RRRD2929',
            $industry,
        );
    }

    /** Returns the n-th TLV inside the outer SEQUENCE (0-based). */
    private static function elementAt(string $der, int $index): string
    {
        $offset = self::headerLength($der, 0);
        for ($i = 0; ; $i++) {
            $length = self::headerLength($der, $offset) + self::contentLength($der, $offset);
            if ($i === $index) {
                return substr($der, $offset, $length);
            }
            $offset += $length;
        }
    }

    private static function firstElement(string $der): string
    {
        return self::elementAt($der, 0);
    }

    private static function headerLength(string $der, int $offset): int
    {
        $first = ord($der[$offset + 1]);

        return $first < 0x80 ? 2 : 2 + ($first & 0x7F);
    }

    private static function contentLength(string $der, int $offset): int
    {
        $first = ord($der[$offset + 1]);
        if ($first < 0x80) {
            return $first;
        }

        $length = 0;
        for ($i = 0; $i < ($first & 0x7F); $i++) {
            $length = ($length << 8) | ord($der[$offset + 2 + $i]);
        }

        return $length;
    }
}
