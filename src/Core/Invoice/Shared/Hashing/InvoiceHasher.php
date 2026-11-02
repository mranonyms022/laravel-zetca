<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Invoice\Shared\Hashing;

use CodingTriplets\Zatca\Core\Invoice\Shared\Xml\SecureXml;
use CodingTriplets\Zatca\Core\Invoice\Shared\Xml\UblInvoiceWriter;

/**
 * ZATCA invoice hash: the signature's XPath transforms (drop UBLExtensions, cac:Signature and the QR
 * reference), C14N 1.1 of the remainder, SHA-256. Result is the binary digest; base64 is the PIH/DigestValue.
 */
final class InvoiceHasher
{
    public function canonical(string $xml): string
    {
        $doc = SecureXml::load($xml);
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('ext', UblInvoiceWriter::NS_EXT);
        $xpath->registerNamespace('cac', UblInvoiceWriter::NS_CAC);
        $xpath->registerNamespace('cbc', UblInvoiceWriter::NS_CBC);

        foreach (['/*/ext:UBLExtensions', '/*/cac:Signature', "/*/cac:AdditionalDocumentReference[cbc:ID='QR']"] as $query) {
            $nodes = $xpath->query($query);
            foreach ($nodes === false ? [] : iterator_to_array($nodes) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        $canonical = $doc->documentElement?->C14N(false, false);
        if ($canonical === false || $canonical === null) {
            throw new \RuntimeException('Canonicalization failed.');
        }

        return $canonical;
    }

    /** @return string raw 32-byte SHA-256 digest */
    public function digest(string $xml): string
    {
        return hash('sha256', $this->canonical($xml), true);
    }

    public function base64(string $xml): string
    {
        return base64_encode($this->digest($xml));
    }
}
