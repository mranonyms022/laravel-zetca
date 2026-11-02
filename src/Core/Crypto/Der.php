<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Crypto;

/**
 * Minimal DER (ASN.1) encoder: only the constructs needed for PKCS#10 CSRs.
 * Kept deliberately small so every byte written to ZATCA is explicit and testable.
 */
final class Der
{
    public static function sequence(string ...$items): string
    {
        return self::tlv(0x30, implode('', $items));
    }

    public static function set(string ...$items): string
    {
        return self::tlv(0x31, implode('', $items));
    }

    public static function integer(int $value): string
    {
        if ($value < 0) {
            throw new \InvalidArgumentException('Negative integers are not supported.');
        }

        $bytes = ltrim(pack('J', $value), "\x00");
        if ($bytes === '' || (ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }

        return self::tlv(0x02, $bytes);
    }

    public static function oid(string $dotted): string
    {
        $parts = array_map('intval', explode('.', $dotted));
        if (count($parts) < 2) {
            throw new \InvalidArgumentException("Invalid OID [{$dotted}].");
        }

        $body = chr(40 * $parts[0] + $parts[1]);
        foreach (array_slice($parts, 2) as $arc) {
            $chunk = chr($arc & 0x7F);
            $arc >>= 7;
            while ($arc > 0) {
                $chunk = chr(0x80 | ($arc & 0x7F)) . $chunk;
                $arc >>= 7;
            }
            $body .= $chunk;
        }

        return self::tlv(0x06, $body);
    }

    public static function utf8String(string $value): string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            throw new \InvalidArgumentException('UTF8String value is not valid UTF-8.');
        }

        return self::tlv(0x0C, $value);
    }

    public static function printableString(string $value): string
    {
        if (preg_match("/^[A-Za-z0-9 '()+,\\-.\\/:=?]*$/", $value) !== 1) {
            throw new \InvalidArgumentException('Value contains characters not allowed in PrintableString.');
        }

        return self::tlv(0x13, $value);
    }

    public static function octetString(string $bytes): string
    {
        return self::tlv(0x04, $bytes);
    }

    public static function bitString(string $bytes): string
    {
        return self::tlv(0x03, "\x00" . $bytes);
    }

    /** Context-specific constructed tag [n] (used for both EXPLICIT and IMPLICIT constructed types). */
    public static function contextConstructed(int $tag, string $content): string
    {
        return self::tlv(0xA0 | $tag, $content);
    }

    public static function tlv(int $tag, string $content): string
    {
        return chr($tag) . self::length(strlen($content)) . $content;
    }

    public static function length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    public static function pemToDer(string $pem): string
    {
        $body = preg_replace('/-----(BEGIN|END) [A-Z0-9 ]+-----|\s+/', '', $pem);
        $der = base64_decode((string) $body, true);
        if ($der === false || $der === '') {
            throw new \InvalidArgumentException('Invalid PEM data.');
        }

        return $der;
    }

    public static function derToPem(string $der, string $label): string
    {
        return "-----BEGIN {$label}-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END {$label}-----\n";
    }
}
