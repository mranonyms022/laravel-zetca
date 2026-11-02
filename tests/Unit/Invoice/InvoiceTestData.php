<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Tests\Unit\Invoice;

use CodingTriplets\Zatca\Core\Crypto\EcPrivateKey;
use CodingTriplets\Zatca\Core\Invoice\Shared\Generation\SigningCredentials;
use CodingTriplets\Zatca\Core\Invoice\Shared\Signing\CertificateInfo;

/** Shared payloads and a throw-away self-signed signing identity for invoice tests. */
final class InvoiceTestData
{
    public const NOW = '2026-11-01T12:00:00';

    /** @return array<string, mixed> */
    public static function seller(): array
    {
        return [
            'name' => 'Maximum Speed Tech Supply LTD',
            'vat_number' => '399999999900003',
            'id_scheme' => 'CRN',
            'id_value' => '1010010000',
            'address' => [
                'street' => 'Prince Sultan', 'building_number' => '2322', 'district' => 'Al-Murabba',
                'city' => 'Riyadh', 'postal_code' => '23333', 'country' => 'SA',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function buyer(): array
    {
        return [
            'name' => 'Fatoora Samples LTD',
            'vat_number' => '399999999800003',
            'address' => [
                'street' => 'Salah Al-Din', 'building_number' => '1111', 'district' => 'Al-Murooj',
                'city' => 'Riyadh', 'postal_code' => '12222', 'country' => 'SA',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function b2c(array $overrides = []): array
    {
        return array_replace([
            'workflow' => 'b2c',
            'invoice_number' => 'SME00010',
            'issued_at' => '2026-11-01T10:00:00',
            'seller' => self::seller(),
            'lines' => [
                ['name' => 'Book', 'quantity' => '33', 'unit_price' => '3.00', 'discount' => '0.10', 'vat_category' => 'S', 'vat_rate' => '15'],
                ['name' => 'Pen', 'quantity' => '3', 'unit_price' => '34.00', 'vat_category' => 'S', 'vat_rate' => '15'],
            ],
        ], $overrides);
    }

    /** @return array<string, mixed> */
    public static function b2b(array $overrides = []): array
    {
        return self::b2c(array_replace(['workflow' => 'b2b', 'buyer' => self::buyer(), 'supply_date' => '2026-11-01'], $overrides));
    }

    public static function credentials(): SigningCredentials
    {
        static $credentials = null;
        if ($credentials === null) {
            $key = EcPrivateKey::generate();
            $pk = openssl_pkey_get_private($key->toPem());
            $csr = openssl_csr_new(['commonName' => 'laravel-zatca test'], $pk, ['digest_alg' => 'sha256']);
            openssl_x509_export(openssl_csr_sign($csr, null, $pk, 30, ['digest_alg' => 'sha256'], 4242), $pem);
            $credentials = new SigningCredentials(CertificateInfo::fromCertificate($pem), $key);
        }

        return $credentials;
    }
}
