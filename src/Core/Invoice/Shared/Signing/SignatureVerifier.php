<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Invoice\Shared\Signing;

use CodingTriplets\Zatca\Core\Invoice\Shared\Hashing\InvoiceHasher;
use CodingTriplets\Zatca\Core\Invoice\Shared\QRCode\QrCode;
use CodingTriplets\Zatca\Core\Invoice\Shared\Xml\SecureXml;
use CodingTriplets\Zatca\Core\Invoice\Shared\Xml\SignatureTemplate;
use CodingTriplets\Zatca\Core\Invoice\Shared\Xml\UblInvoiceWriter;

/**
 * Independent verification of a signed ZATCA invoice: invoice hash, ECDSA signature, certificate digest,
 * SignedProperties digest and QR consistency. Returns a list of problems (empty = valid).
 */
final class SignatureVerifier
{
    public function __construct(private readonly InvoiceHasher $hasher = new InvoiceHasher())
    {
    }

    /** @return list<string> */
    public function verify(string $xml): array
    {
        $doc = SecureXml::load($xml);
        $x = new \DOMXPath($doc);
        $x->registerNamespace('cac', UblInvoiceWriter::NS_CAC);
        $x->registerNamespace('cbc', UblInvoiceWriter::NS_CBC);
        $x->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
        $x->registerNamespace('xades', 'http://uri.etsi.org/01903/v1.3.2#');
        $s = static fn (string $q): string => trim((string) $x->evaluate("string({$q})"));

        $problems = [];
        $digestValue = $s('//ds:Reference[@Id="invoiceSignedData"]/ds:DigestValue');
        if (! hash_equals($this->hasher->base64($xml), $digestValue)) {
            $problems[] = 'Invoice hash does not match ds:DigestValue.';
        }

        try {
            $cert = CertificateInfo::fromCertificate($s('//ds:X509Certificate'));
        } catch (\Throwable) {
            return [...$problems, 'Embedded certificate cannot be parsed.'];
        }

        $signature = base64_decode($s('//ds:SignatureValue'), true);
        if ($signature === false || openssl_verify((string) base64_decode($digestValue), $signature, $cert->pem, OPENSSL_ALGO_SHA256) !== 1) {
            $problems[] = 'Signature value does not verify against the certificate.';
        }

        if ($s('//xades:CertDigest/ds:DigestValue') !== $cert->digest) {
            $problems[] = 'Certificate digest does not match the embedded certificate.';
        }

        $properties = SignatureTemplate::signedPropertiesForDigest(
            $s('//xades:SigningTime'),
            $s('//xades:CertDigest/ds:DigestValue'),
            $s('//ds:X509IssuerName'),
            $s('//ds:X509SerialNumber'),
        );
        if (base64_encode(hash('sha256', $properties)) !== $s('//ds:Reference[@URI="#xadesSignedProperties"]/ds:DigestValue')) {
            $problems[] = 'SignedProperties digest does not match.';
        }

        try {
            $fields = QrCode::decode($s("//cac:AdditionalDocumentReference[cbc:ID='QR']/cac:Attachment/cbc:EmbeddedDocumentBinaryObject"));
            if (($fields[6] ?? null) !== $digestValue) {
                $problems[] = 'QR invoice hash (tag 6) does not match.';
            }
            if (($fields[7] ?? null) !== $s('//ds:SignatureValue')) {
                $problems[] = 'QR signature (tag 7) does not match.';
            }
            if (($fields[8] ?? null) !== $cert->publicKeyDer) {
                $problems[] = 'QR public key (tag 8) does not match the certificate.';
            }
        } catch (\InvalidArgumentException $e) {
            $problems[] = 'QR code: ' . $e->getMessage();
        }

        return $problems;
    }
}
