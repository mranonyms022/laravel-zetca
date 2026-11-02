<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Invoices;

/** Monotonic-enough ULID (48-bit ms time + 80 random bits, Crockford base32). Works on every Laravel version. */
final class Ulid
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function generate(): string
    {
        $time = (int) floor(microtime(true) * 1000);
        $out = '';
        for ($i = 9; $i >= 0; $i--) {
            $out = self::ALPHABET[$time % 32] . $out;
            $time = intdiv($time, 32);
        }

        $random = random_bytes(10);
        $bits = '';
        foreach (str_split($random) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec($chunk)];
        }

        return $out;
    }
}
