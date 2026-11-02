<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Crypto;

use CodingTriplets\Zatca\Core\Domain\Exceptions\CredentialException;

/**
 * Public certificate returned by ZATCA as `binarySecurityToken`
 * (base64 of the base64-encoded DER certificate). Public data: safe to store and display.
 */
final class X509Certificate
{
    private function __construct(
        public readonly string $binarySecurityToken,
        public readonly string $pem,
        public readonly string $serialNumber,
        public readonly string $issuer,
        public readonly string $subject,
        public readonly \DateTimeImmutable $validFrom,
        public readonly \DateTimeImmutable $validUntil,
        public readonly string $fingerprint,
    ) {
    }

    public static function fromBinarySecurityToken(string $token): self
    {
        $inner = base64_decode($token, true);
        if ($inner === false || $inner === '') {
            throw new CredentialException('binarySecurityToken is not valid base64.');
        }

        $der = base64_decode(trim($inner), true);
        if ($der === false || $der === '') {
            throw new CredentialException('binarySecurityToken does not contain a base64 DER certificate.');
        }

        $pem = Der::derToPem($der, 'CERTIFICATE');
        $parsed = openssl_x509_parse($pem);
        if ($parsed === false) {
            throw new CredentialException('binarySecurityToken does not contain a valid X.509 certificate.');
        }

        return new self(
            $token,
            $pem,
            (string) ($parsed['serialNumberHex'] ?? $parsed['serialNumber'] ?? ''),
            self::dn($parsed['issuer'] ?? []),
            self::dn($parsed['subject'] ?? []),
            (new \DateTimeImmutable('@' . (int) $parsed['validFrom_time_t'])),
            (new \DateTimeImmutable('@' . (int) $parsed['validTo_time_t'])),
            hash('sha256', $der),
        );
    }

    /** @param array<string, string|list<string>> $parts */
    private static function dn(array $parts): string
    {
        $out = [];
        foreach ($parts as $k => $v) {
            foreach ((array) $v as $item) {
                $out[] = "{$k}={$item}";
            }
        }

        return implode(', ', $out);
    }
}
