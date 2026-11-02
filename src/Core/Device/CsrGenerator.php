<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Device;

use CodingTriplets\Zatca\Core\Crypto\Der;
use CodingTriplets\Zatca\Core\Crypto\EcPrivateKey;

/**
 * Builds the PKCS#10 CSR sent to ZATCA, signed with the device key (proof of possession).
 *
 * Built in pure PHP (no OpenSSL config or temporary files). The output's to-be-signed part is
 * byte-identical to what OpenSSL's `req` command produces from the configuration used by
 * established ZATCA integrations (verified by a golden-file test). Notable details:
 *  - All directory strings are UTF8String (Arabic names work); countryName is PrintableString.
 *  - The EGS serial uses OpenSSL's "SN" attribute, which is **surname (2.5.4.4)**. This is what
 *    OpenSSL-based integrations send and ZATCA accepts. serialNumber (2.5.4.5) is selectable. ⚠
 *  - Certificate template name extension 1.3.6.1.4.1.311.20.2 as UTF8String, per environment.
 */
final class CsrGenerator
{
    public const SERIAL_ATTRIBUTE_SURNAME = '2.5.4.4';
    public const SERIAL_ATTRIBUTE_SERIAL_NUMBER = '2.5.4.5';

    private const OID_CN = '2.5.4.3';
    private const OID_OU = '2.5.4.11';
    private const OID_O = '2.5.4.10';
    private const OID_C = '2.5.4.6';
    private const OID_UID = '0.9.2342.19200300.100.1.1';
    private const OID_TITLE = '2.5.4.12';
    private const OID_REGISTERED_ADDRESS = '2.5.4.26';
    private const OID_BUSINESS_CATEGORY = '2.5.4.15';
    private const OID_EXTENSION_REQUEST = '1.2.840.113549.1.9.14';
    private const OID_TEMPLATE_NAME = '1.3.6.1.4.1.311.20.2';
    private const OID_SUBJECT_ALT_NAME = '2.5.29.17';
    private const OID_ECDSA_SHA256 = '1.2.840.10045.4.3.2';

    public function __construct(private readonly string $serialAttributeOid = self::SERIAL_ATTRIBUTE_SURNAME)
    {
        if (! in_array($serialAttributeOid, [self::SERIAL_ATTRIBUTE_SURNAME, self::SERIAL_ATTRIBUTE_SERIAL_NUMBER], true)) {
            throw new \InvalidArgumentException('Unsupported EGS serial attribute OID.');
        }
    }

    /** @return string PEM "CERTIFICATE REQUEST" */
    public function generate(CsrProfile $profile, EcPrivateKey $key): string
    {
        $info = $this->certificationRequestInfo($profile, $key);

        $der = Der::sequence(
            $info,
            Der::sequence(Der::oid(self::OID_ECDSA_SHA256)),
            Der::bitString($key->sign($info)),
        );

        return Der::derToPem($der, 'CERTIFICATE REQUEST');
    }

    /** The to-be-signed part (public for golden-file testing). */
    public function certificationRequestInfo(CsrProfile $profile, EcPrivateKey $key): string
    {
        $subject = Der::sequence(
            self::rdn(self::OID_CN, Der::utf8String($profile->commonName)),
            self::rdn(self::OID_OU, Der::utf8String($profile->organizationUnit)),
            self::rdn(self::OID_O, Der::utf8String($profile->organizationName)),
            self::rdn(self::OID_C, Der::printableString($profile->countryCode)),
        );

        $directoryName = Der::sequence(
            self::rdn($this->serialAttributeOid, Der::utf8String($profile->serialNumber->value)),
            self::rdn(self::OID_UID, Der::utf8String($profile->vatNumber->value)),
            self::rdn(self::OID_TITLE, Der::utf8String($profile->invoiceType->value)),
            self::rdn(self::OID_REGISTERED_ADDRESS, Der::utf8String($profile->location)),
            self::rdn(self::OID_BUSINESS_CATEGORY, Der::utf8String($profile->industry)),
        );

        $extensions = Der::sequence(
            Der::sequence(
                Der::oid(self::OID_TEMPLATE_NAME),
                Der::octetString(Der::utf8String($profile->environment->certificateTemplateName())),
            ),
            Der::sequence(
                Der::oid(self::OID_SUBJECT_ALT_NAME),
                // GeneralNames ::= SEQUENCE OF GeneralName; directoryName [4] (explicit: Name is a CHOICE)
                Der::octetString(Der::sequence(Der::contextConstructed(4, $directoryName))),
            ),
        );

        $attributes = Der::contextConstructed(0, Der::sequence(
            Der::oid(self::OID_EXTENSION_REQUEST),
            Der::set($extensions),
        ));

        return Der::sequence(Der::integer(0), $subject, $key->publicKeyDer(), $attributes);
    }

    private static function rdn(string $oid, string $value): string
    {
        return Der::set(Der::sequence(Der::oid($oid), $value));
    }
}
