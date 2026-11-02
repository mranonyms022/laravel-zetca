<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Invoice\Shared\Xml;

/**
 * Hardened XML parsing: no DTDs, no entity expansion, no network access (XXE protection).
 */
final class SecureXml
{
    public static function load(string $xml): \DOMDocument
    {
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml) === 1) {
            throw new \InvalidArgumentException('XML with a DOCTYPE or ENTITY declaration is not accepted.');
        }

        $doc = new \DOMDocument();
        $doc->preserveWhiteSpace = true;
        $previous = libxml_use_internal_errors(true);
        try {
            $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS * 0);
            $errors = libxml_get_errors();
            libxml_clear_errors();
        } finally {
            libxml_use_internal_errors($previous);
        }

        if (! $ok || $doc->documentElement === null) {
            $first = isset($errors[0]) ? trim($errors[0]->message) : 'unknown error';
            throw new \InvalidArgumentException("Invalid XML: {$first}");
        }

        return $doc;
    }
}
