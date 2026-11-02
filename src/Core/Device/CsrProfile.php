<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Device;

use CodingTriplets\Zatca\Core\Domain\Enums\Environment;
use CodingTriplets\Zatca\Core\Domain\Exceptions\InvalidValue;
use CodingTriplets\Zatca\Core\Domain\ValueObjects\EgsSerialNumber;
use CodingTriplets\Zatca\Core\Domain\ValueObjects\InvoiceTypeFlags;
use CodingTriplets\Zatca\Core\Domain\ValueObjects\Tin;
use CodingTriplets\Zatca\Core\Domain\ValueObjects\VatNumber;

/**
 * CSR field contents (Security Features Implementation Standards v1.1, Table 1; ARCHITECTURE 2.1).
 *
 * Subject:  CN = device name / asset number, OU = branch name (VAT group: 10-digit member TIN),
 *           O = taxpayer name, C = SA.
 * SAN dirName: EGS serial, UID = VAT number, title = invoice type (TSCZ),
 *              registeredAddress = location, businessCategory = industry.
 */
final class CsrProfile
{
    public function __construct(
        public readonly Environment $environment,
        public readonly string $commonName,
        public readonly EgsSerialNumber $serialNumber,
        public readonly VatNumber $vatNumber,
        public readonly string $organizationUnit,
        public readonly string $organizationName,
        public readonly InvoiceTypeFlags $invoiceType,
        public readonly string $location,
        public readonly string $industry,
        public readonly string $countryCode = 'SA',
    ) {
        foreach ([
            'csr.common_name' => $commonName,
            'csr.organization_unit' => $organizationUnit,
            'csr.organization_name' => $organizationName,
            'csr.location' => $location,
            'csr.industry' => $industry,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw InvalidValue::for($field, 'is required');
            }
            if (mb_strlen($value) > 128) {
                throw InvalidValue::for($field, 'must be at most 128 characters');
            }
        }

        if ($vatNumber->isGroup()) {
            // VAT group (11th digit = 1): OU must carry the 10-digit TIN of the member being onboarded.
            Tin::fromString($organizationUnit, 'csr.organization_unit');
        }

        if (preg_match('/^[A-Z]{2}$/', $countryCode) !== 1) {
            throw InvalidValue::for('csr.country', 'must be an ISO 3166 alpha-2 code');
        }
    }
}
