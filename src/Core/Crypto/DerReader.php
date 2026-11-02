<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Crypto;

use CodingTriplets\Zatca\Core\Domain\Exceptions\CredentialException;

/** Minimal DER reader: enough to walk an X.509 certificate. */
final class DerReader
{
    /**
     * Reads the TLV at $offset.
     *
     * @return array{tag: int, header: int, length: int, content: string, end: int}
     */
    public static function read(string $der, int $offset = 0): array
    {
        if ($offset + 2 > strlen($der)) {
            throw new CredentialException('Truncated DER data.');
        }
        $tag = ord($der[$offset]);
        $len = ord($der[$offset + 1]);
        $header = 2;
        if ($len & 0x80) {
            $n = $len & 0x7F;
            if ($n === 0 || $n > 4 || $offset + 2 + $n > strlen($der)) {
                throw new CredentialException('Unsupported DER length.');
            }
            $len = 0;
            for ($i = 0; $i < $n; $i++) {
                $len = ($len << 8) | ord($der[$offset + 2 + $i]);
            }
            $header += $n;
        }
        if ($offset + $header + $len > strlen($der)) {
            throw new CredentialException('Truncated DER data.');
        }

        return [
            'tag' => $tag,
            'header' => $header,
            'length' => $len,
            'content' => substr($der, $offset + $header, $len),
            'end' => $offset + $header + $len,
        ];
    }

    /** @return list<array{tag: int, header: int, length: int, content: string, end: int}> children of a constructed value */
    public static function children(string $content): array
    {
        $out = [];
        for ($o = 0; $o < strlen($content);) {
            $tlv = self::read($content, $o);
            $out[] = $tlv;
            $o = $tlv['end'];
        }

        return $out;
    }

    public static function oid(string $content): string
    {
        $first = ord($content[0]);
        $parts = [intdiv($first, 40), $first % 40];
        $value = 0;
        for ($i = 1; $i < strlen($content); $i++) {
            $b = ord($content[$i]);
            $value = ($value << 7) | ($b & 0x7F);
            if (($b & 0x80) === 0) {
                $parts[] = $value;
                $value = 0;
            }
        }

        return implode('.', $parts);
    }
}
