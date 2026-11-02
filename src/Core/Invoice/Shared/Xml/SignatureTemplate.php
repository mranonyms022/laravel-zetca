<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Invoice\Shared\Xml;

/**
 * Signature blocks, laid out exactly as in the ZATCA SDK samples (238-R3.3.3).
 *
 * The SignedProperties digest is computed over SIGNED_PROPERTIES_FOR_DIGEST (namespace declarations on
 * each ds element, as a standalone serialization of the element). This reproduces the digest in the
 * official sample byte-for-byte (tests/Unit/Invoice/SdkGoldenTest). The element written into the
 * document must keep the same indentation, which UBL_EXTENSIONS does.
 */
final class SignatureTemplate
{
    public const UBL_EXTENSIONS = <<<'XML'
<ext:UBLExtensions>
    <ext:UBLExtension>
        <ext:ExtensionURI>urn:oasis:names:specification:ubl:dsig:enveloped:xades</ext:ExtensionURI>
        <ext:ExtensionContent>
            <sig:UBLDocumentSignatures xmlns:sig="urn:oasis:names:specification:ubl:schema:xsd:CommonSignatureComponents-2" xmlns:sac="urn:oasis:names:specification:ubl:schema:xsd:SignatureAggregateComponents-2" xmlns:sbc="urn:oasis:names:specification:ubl:schema:xsd:SignatureBasicComponents-2">
                <sac:SignatureInformation>
                    <cbc:ID>urn:oasis:names:specification:ubl:signature:1</cbc:ID>
                    <sbc:ReferencedSignatureID>urn:oasis:names:specification:ubl:signature:Invoice</sbc:ReferencedSignatureID>
                    <ds:Signature xmlns:ds="http://www.w3.org/2000/09/xmldsig#" Id="signature">
                        <ds:SignedInfo>
                            <ds:CanonicalizationMethod Algorithm="http://www.w3.org/2006/12/xml-c14n11"/>
                            <ds:SignatureMethod Algorithm="http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256"/>
                            <ds:Reference Id="invoiceSignedData" URI="">
                                <ds:Transforms>
                                    <ds:Transform Algorithm="http://www.w3.org/TR/1999/REC-xpath-19991116">
                                        <ds:XPath>not(//ancestor-or-self::ext:UBLExtensions)</ds:XPath>
                                    </ds:Transform>
                                    <ds:Transform Algorithm="http://www.w3.org/TR/1999/REC-xpath-19991116">
                                        <ds:XPath>not(//ancestor-or-self::cac:Signature)</ds:XPath>
                                    </ds:Transform>
                                    <ds:Transform Algorithm="http://www.w3.org/TR/1999/REC-xpath-19991116">
                                        <ds:XPath>not(//ancestor-or-self::cac:AdditionalDocumentReference[cbc:ID='QR'])</ds:XPath>
                                    </ds:Transform>
                                    <ds:Transform Algorithm="http://www.w3.org/2006/12/xml-c14n11"/>
                                </ds:Transforms>
                                <ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>
                                <ds:DigestValue>SET_INVOICE_HASH</ds:DigestValue>
                            </ds:Reference>
                            <ds:Reference Type="http://www.w3.org/2000/09/xmldsig#SignatureProperties" URI="#xadesSignedProperties">
                                <ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>
                                <ds:DigestValue>SET_SIGNED_PROPERTIES_HASH</ds:DigestValue>
                            </ds:Reference>
                        </ds:SignedInfo>
                        <ds:SignatureValue>SET_SIGNATURE_VALUE</ds:SignatureValue>
                        <ds:KeyInfo>
                            <ds:X509Data>
                                <ds:X509Certificate>SET_CERTIFICATE</ds:X509Certificate>
                            </ds:X509Data>
                        </ds:KeyInfo>
                        <ds:Object>
                            <xades:QualifyingProperties xmlns:xades="http://uri.etsi.org/01903/v1.3.2#" Target="signature">
                                <xades:SignedProperties Id="xadesSignedProperties">
                                    <xades:SignedSignatureProperties>
                                        <xades:SigningTime>SET_SIGNING_TIME</xades:SigningTime>
                                        <xades:SigningCertificate>
                                            <xades:Cert>
                                                <xades:CertDigest>
                                                    <ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>
                                                    <ds:DigestValue>SET_CERTIFICATE_HASH</ds:DigestValue>
                                                </xades:CertDigest>
                                                <xades:IssuerSerial>
                                                    <ds:X509IssuerName>SET_ISSUER_NAME</ds:X509IssuerName>
                                                    <ds:X509SerialNumber>SET_SERIAL_NUMBER</ds:X509SerialNumber>
                                                </xades:IssuerSerial>
                                            </xades:Cert>
                                        </xades:SigningCertificate>
                                    </xades:SignedSignatureProperties>
                                </xades:SignedProperties>
                            </xades:QualifyingProperties>
                        </ds:Object>
                    </ds:Signature>
                </sac:SignatureInformation>
            </sig:UBLDocumentSignatures>
        </ext:ExtensionContent>
    </ext:UBLExtension>
</ext:UBLExtensions>
XML;

    public const CAC_SIGNATURE = <<<'XML'
<cac:Signature>
      <cbc:ID>urn:oasis:names:specification:ubl:signature:Invoice</cbc:ID>
      <cbc:SignatureMethod>urn:oasis:names:specification:ubl:dsig:enveloped:xades</cbc:SignatureMethod>
</cac:Signature>
XML;

    public static function signedPropertiesForDigest(string $signingTime, string $certificateHash, string $issuerName, string $serialNumber): string
    {
        $ds = 'xmlns:ds="http://www.w3.org/2000/09/xmldsig#"';
        $pad = static fn (int $n): string => str_repeat(' ', $n);
        $e = static fn (string $v): string => UblInvoiceWriter::escape($v);

        return '<xades:SignedProperties xmlns:xades="http://uri.etsi.org/01903/v1.3.2#" Id="xadesSignedProperties">' . "\n"
            . $pad(36) . '<xades:SignedSignatureProperties>' . "\n"
            . $pad(40) . '<xades:SigningTime>' . $e($signingTime) . '</xades:SigningTime>' . "\n"
            . $pad(40) . '<xades:SigningCertificate>' . "\n"
            . $pad(44) . '<xades:Cert>' . "\n"
            . $pad(48) . '<xades:CertDigest>' . "\n"
            . $pad(52) . '<ds:DigestMethod ' . $ds . ' Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>' . "\n"
            . $pad(52) . '<ds:DigestValue ' . $ds . '>' . $e($certificateHash) . '</ds:DigestValue>' . "\n"
            . $pad(48) . '</xades:CertDigest>' . "\n"
            . $pad(48) . '<xades:IssuerSerial>' . "\n"
            . $pad(52) . '<ds:X509IssuerName ' . $ds . '>' . $e($issuerName) . '</ds:X509IssuerName>' . "\n"
            . $pad(52) . '<ds:X509SerialNumber ' . $ds . '>' . $e($serialNumber) . '</ds:X509SerialNumber>' . "\n"
            . $pad(48) . '</xades:IssuerSerial>' . "\n"
            . $pad(44) . '</xades:Cert>' . "\n"
            . $pad(40) . '</xades:SigningCertificate>' . "\n"
            . $pad(36) . '</xades:SignedSignatureProperties>' . "\n"
            . $pad(32) . '</xades:SignedProperties>';
    }
}
