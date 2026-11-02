<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Invoice\Shared\Signing;

use CodingTriplets\Zatca\Core\Crypto\EcPrivateKey;
use CodingTriplets\Zatca\Core\Domain\Exceptions\CredentialException;
use CodingTriplets\Zatca\Core\Invoice\Shared\Hashing\InvoiceHasher;
use CodingTriplets\Zatca\Core\Invoice\Shared\QRCode\QrCode;
use CodingTriplets\Zatca\Core\Invoice\Shared\Xml\SignatureTemplate;
use CodingTriplets\Zatca\Core\Invoice\Shared\Xml\UblInvoiceWriter;

/**
 * Signs a ZATCA invoice (XAdES-BES, ECDSA-SHA256) and embeds the QR code.
 *
 * The input XML already contains the final layout with placeholders, so the invoice hash computed here is
 * the hash of the delivered document. After filling the placeholders the result is re-hashed and the
 * signature verified (self-check): a document that fails is never returned.
 */
final class XadesSigner
{
    public function __construct(private readonly InvoiceHasher $hasher = new InvoiceHasher())
    {
    }

    /**
     * @param array{seller_name: string, vat_number: string, timestamp: string, total: string, vat_total: string} $qr
     */
    public function sign(string $xml, CertificateInfo $certificate, EcPrivateKey $key, \DateTimeImmutable $signingTime, array $qr, bool $simplified): SignedInvoice
    {
        foreach (['SET_INVOICE_HASH', 'SET_SIGNATURE_VALUE', UblInvoiceWriter::QR_PLACEHOLDER] as $placeholder) {
            if (substr_count($xml, $placeholder) !== 1) {
                throw new \LogicException("Unsigned invoice must contain the {$placeholder} placeholder exactly once.");
            }
        }

        $digest = $this->hasher->digest($xml);
        $hash = base64_encode($digest);
        $signature = base64_encode($key->sign($digest));
        $time = $signingTime->setTimezone(new \DateTimeZone('Asia/Riyadh'))->format('Y-m-d\TH:i:s');

        $propertiesDigest = base64_encode(hash('sha256', SignatureTemplate::signedPropertiesForDigest(
            $time, $certificate->digest, $certificate->issuerName, $certificate->serialNumber,
        )));

        $fields = [
            1 => $qr['seller_name'],
            2 => $qr['vat_number'],
            3 => $qr['timestamp'],
            4 => $qr['total'],
            5 => $qr['vat_total'],
            6 => $hash,
            7 => $signature,
            8 => $certificate->publicKeyDer,
        ];
        if ($simplified) {
            $fields[9] = $certificate->signatureBytes;
        }
        $qrCode = QrCode::encode($fields);

        $e = static fn (string $v): string => UblInvoiceWriter::escape($v);
        $signed = strtr($xml, [
            'SET_INVOICE_HASH' => $hash,
            'SET_SIGNED_PROPERTIES_HASH' => $propertiesDigest,
            'SET_SIGNATURE_VALUE' => $signature,
            'SET_CERTIFICATE_HASH' => $certificate->digest,
            'SET_CERTIFICATE' => $certificate->base64,
            'SET_SIGNING_TIME' => $time,
            'SET_ISSUER_NAME' => $e($certificate->issuerName),
            'SET_SERIAL_NUMBER' => $certificate->serialNumber,
            UblInvoiceWriter::QR_PLACEHOLDER => $qrCode,
        ]);

        // Self-check: the delivered document must hash to the signed value and the signature must verify.
        if (! hash_equals($digest, $this->hasher->digest($signed))) {
            throw new \LogicException('Invoice hash changed while embedding the signature.');
        }
        if (openssl_verify($digest, (string) base64_decode($signature), $certificate->pem, OPENSSL_ALGO_SHA256) !== 1) {
            throw new CredentialException('The device private key does not match its certificate.');
        }

        return new SignedInvoice($signed, $hash, $signature, $qrCode, $time);
    }
}
