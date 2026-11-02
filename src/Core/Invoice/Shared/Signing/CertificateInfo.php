<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Invoice\Shared\Signing;

use Brick\Math\BigInteger;
use CodingTriplets\Zatca\Core\Crypto\Der;
use CodingTriplets\Zatca\Core\Crypto\DerReader;
use CodingTriplets\Zatca\Core\Domain\Exceptions\CredentialException;

/**
 * Values derived from the device certificate that go into the XAdES signature and the QR code.
 * Formats verified against the ZATCA SDK samples (tests/Unit/Invoice/SdkGoldenTest).
 */
final class CertificateInfo
{
    private const ATTRIBUTE_NAMES = [
        '2.5.4.3' => 'CN',
        '2.5.4.4' => 'SN',
        '2.5.4.5' => 'SERIALNUMBER',
        '2.5.4.6' => 'C',
        '2.5.4.7' => 'L',
        '2.5.4.8' => 'ST',
        '2.5.4.10' => 'O',
        '2.5.4.11' => 'OU',
        '0.9.2342.19200300.100.1.25' => 'DC',
        '0.9.2342.19200300.100.1.1' => 'UID',
        '1.2.840.113549.1.9.1' => 'E',
    ];

    private function __construct(
        /** base64 of the DER certificate (content of ds:X509Certificate) */
        public readonly string $base64,
        public readonly string $pem,
        /** XAdES CertDigest: base64(hex(sha256(base64 certificate text))) */
        public readonly string $digest,
        /** RFC 4514-style issuer name, most specific RDN first, e.g. "CN=..., DC=extgazt, DC=gov, DC=local" */
        public readonly string $issuerName,
        /** serial number in decimal */
        public readonly string $serialNumber,
        /** SubjectPublicKeyInfo DER (QR tag 8) */
        public readonly string $publicKeyDer,
        /** certificate signature value bytes (QR tag 9, simplified invoices) */
        public readonly string $signatureBytes,
    ) {
    }

    /** @param string $certificate PEM, base64 DER, or ZATCA binarySecurityToken (base64 of base64 DER) */
    public static function fromCertificate(string $certificate): self
    {
        $b64 = self::normalise($certificate);
        $der = base64_decode($b64, true);
        if ($der === false || $der === '') {
            throw new CredentialException('Certificate is not valid base64.');
        }

        try {
            $cert = DerReader::read($der);
            [$tbs, , $signature] = DerReader::children($cert['content']) + [null, null, null];
            if ($tbs === null || $signature === null || $signature['tag'] !== 0x03) {
                throw new CredentialException('Unexpected certificate structure.');
            }
            $fields = DerReader::children($tbs['content']);
            $i = $fields[0]['tag'] === 0xA0 ? 1 : 0;      // optional [0] version
            $serial = $fields[$i]['content'];
            $issuer = $fields[$i + 2]['content'];
            $spki = $fields[$i + 5];
        } catch (\Throwable $e) {
            throw $e instanceof CredentialException ? $e : new CredentialException('Unable to parse certificate.', 0, $e);
        }

        $pem = Der::derToPem($der, 'CERTIFICATE');

        return new self(
            $b64,
            $pem,
            base64_encode(hash('sha256', $b64)),
            self::issuerName($issuer),
            (string) BigInteger::fromBase(bin2hex($serial) ?: '0', 16),
            self::tlvBytes($tbs['content'], $spki),
            substr($signature['content'], 1),               // skip the BIT STRING unused-bits byte
        );
    }

    private static function normalise(string $certificate): string
    {
        $certificate = trim($certificate);
        if (str_contains($certificate, '-----BEGIN')) {
            return base64_encode(Der::pemToDer($certificate));
        }

        $decoded = base64_decode($certificate, true);
        if ($decoded !== false && str_starts_with($decoded, 'MII')) {
            return trim($decoded);                           // binarySecurityToken
        }

        return (string) preg_replace('/\s+/', '', $certificate);
    }

    /** @param array{tag: int, header: int, length: int, content: string, end: int} $tlv */
    private static function tlvBytes(string $parent, array $tlv): string
    {
        return substr($parent, $tlv['end'] - $tlv['header'] - $tlv['length'], $tlv['header'] + $tlv['length']);
    }

    private static function issuerName(string $name): string
    {
        $parts = [];
        foreach (DerReader::children($name) as $rdn) {
            $atvs = [];
            foreach (DerReader::children($rdn['content']) as $atv) {
                [$oid, $value] = DerReader::children($atv['content']);
                $dotted = DerReader::oid($oid['content']);
                $atvs[] = (self::ATTRIBUTE_NAMES[$dotted] ?? $dotted) . '=' . $value['content'];
            }
            $parts[] = implode('+', $atvs);
        }

        return implode(', ', array_reverse($parts));
    }
}
