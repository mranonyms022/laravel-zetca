<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Invoice\Shared\QRCode;

/**
 * ZATCA QR payload: TLV (1-byte tag, 1-byte length), base64 encoded.
 *  1 seller name, 2 VAT number, 3 timestamp, 4 invoice total incl. VAT, 5 VAT total,
 *  6 invoice hash (base64 text), 7 ECDSA signature (base64 text), 8 public key (DER),
 *  9 certificate signature (simplified invoices only).
 * Tags 6/7 carry the base64 text as in the official SDK samples.
 */
final class QrCode
{
    /** @param array<int, string> $fields tag => raw value */
    public static function encode(array $fields): string
    {
        ksort($fields);
        $out = '';
        foreach ($fields as $tag => $value) {
            $length = strlen($value);
            if ($tag < 1 || $tag > 255 || $length > 255) {
                throw new \InvalidArgumentException("QR tag {$tag} value is too long ({$length} bytes, max 255).");
            }
            $out .= chr($tag) . chr($length) . $value;
        }

        return base64_encode($out);
    }

    /** @return array<int, string> tag => raw value */
    public static function decode(string $base64): array
    {
        $bytes = base64_decode($base64, true);
        if ($bytes === false) {
            throw new \InvalidArgumentException('QR payload is not base64.');
        }

        $fields = [];
        for ($i = 0, $n = strlen($bytes); $i < $n;) {
            if ($i + 2 > $n) {
                throw new \InvalidArgumentException('Truncated QR TLV.');
            }
            $tag = ord($bytes[$i]);
            $len = ord($bytes[$i + 1]);
            if ($i + 2 + $len > $n) {
                throw new \InvalidArgumentException('Truncated QR TLV.');
            }
            $fields[$tag] = substr($bytes, $i + 2, $len);
            $i += 2 + $len;
        }

        return $fields;
    }
}
