<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Tests\Unit\Invoice;

use CodingTriplets\Zatca\Core\Invoice\Shared\Hashing\InvoiceHasher;
use CodingTriplets\Zatca\Core\Invoice\Shared\QRCode\QrCode;
use CodingTriplets\Zatca\Core\Invoice\Shared\Signing\CertificateInfo;
use CodingTriplets\Zatca\Core\Invoice\Shared\Signing\SignatureVerifier;
use CodingTriplets\Zatca\Core\Invoice\Shared\Xml\SignatureTemplate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Reproduces the hashes and signatures of official ZATCA SDK samples (tests/Fixtures/sdk). */
final class SdkGoldenTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function samples(): array
    {
        $out = [];
        foreach (glob(__DIR__ . '/../../Fixtures/sdk/*.xml') ?: [] as $file) {
            $out[basename($file)] = [$file];
        }

        return $out;
    }

    /** @dataProvider samples */
    #[DataProvider('samples')]
    public function test_sample_verifies_completely(string $file): void
    {
        $this->assertSame([], (new SignatureVerifier())->verify((string) file_get_contents($file)));
    }

    /** @dataProvider samples */
    #[DataProvider('samples')]
    public function test_invoice_hash_matches_digest_value(string $file): void
    {
        $xml = (string) file_get_contents($file);
        preg_match('#<ds:Reference Id="invoiceSignedData" URI="">.*?<ds:DigestValue>(.*?)</ds:DigestValue>#s', $xml, $m);

        $this->assertSame($m[1], (new InvoiceHasher())->base64($xml));
    }

    public function test_certificate_values_match_sample(): void
    {
        $xml = (string) file_get_contents(__DIR__ . '/../../Fixtures/sdk/Simplified_Invoice.xml');
        preg_match('#<ds:X509Certificate>(.*?)</ds:X509Certificate>#s', $xml, $cert);
        preg_match('#<xades:CertDigest>.*?<ds:DigestValue>(.*?)</ds:DigestValue>#s', $xml, $digest);
        preg_match('#<ds:X509IssuerName>(.*?)</ds:X509IssuerName>#', $xml, $issuer);
        preg_match('#<ds:X509SerialNumber>(.*?)</ds:X509SerialNumber>#', $xml, $serial);
        preg_match('#<xades:SigningTime>(.*?)</xades:SigningTime>#', $xml, $time);
        preg_match('#URI="\#xadesSignedProperties">.*?<ds:DigestValue>(.*?)</ds:DigestValue>#s', $xml, $props);

        $info = CertificateInfo::fromCertificate($cert[1]);

        $this->assertSame($digest[1], $info->digest);
        $this->assertSame($issuer[1], $info->issuerName);
        $this->assertSame($serial[1], $info->serialNumber);
        $this->assertSame($props[1], base64_encode(hash('sha256', SignatureTemplate::signedPropertiesForDigest($time[1], $info->digest, $info->issuerName, $info->serialNumber))));
    }

    public function test_simplified_qr_layout(): void
    {
        $xml = (string) file_get_contents(__DIR__ . '/../../Fixtures/sdk/Simplified_Invoice.xml');
        preg_match("#<cbc:ID>QR</cbc:ID>\\s*<cac:Attachment>\\s*<cbc:EmbeddedDocumentBinaryObject[^>]*>(.*?)<#s", $xml, $qr);
        preg_match('#<ds:X509Certificate>(.*?)</ds:X509Certificate>#s', $xml, $cert);
        $fields = QrCode::decode($qr[1]);
        $info = CertificateInfo::fromCertificate($cert[1]);

        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8, 9], array_keys($fields));
        $this->assertSame($info->publicKeyDer, $fields[8]);
        $this->assertSame($info->signatureBytes, $fields[9]);
        $this->assertSame($qr[1], QrCode::encode($fields));
    }
}
