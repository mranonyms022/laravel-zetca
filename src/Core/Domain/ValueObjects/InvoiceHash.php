<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Domain\ValueObjects;

use CodingTriplets\Zatca\Core\Domain\Exceptions\InvalidValue;

/**
 * Base64-encoded SHA-256 invoice hash (used as KSA-13 PIH of the next document).
 */
final class InvoiceHash
{
    /** PIH of the first document in a chain: base64(hex(sha256("0"))) as published by ZATCA. */
    public const GENESIS = 'NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==';

    private function __construct(public readonly string $value)
    {
    }

    public static function fromBase64(string $value): self
    {
        $decoded = base64_decode($value, true);

        if ($decoded === false || $decoded === '') {
            throw InvalidValue::for('invoice_hash', 'must be base64');
        }

        if ($value !== self::GENESIS && strlen($decoded) !== 32) {
            throw InvalidValue::for('invoice_hash', 'must be a base64-encoded 32-byte SHA-256 digest');
        }

        return new self($value);
    }

    public static function fromBinaryDigest(string $digest): self
    {
        if (strlen($digest) !== 32) {
            throw InvalidValue::for('invoice_hash', 'binary SHA-256 digest must be 32 bytes');
        }

        return new self(base64_encode($digest));
    }

    public static function genesis(): self
    {
        return new self(self::GENESIS);
    }

    public function isGenesis(): bool
    {
        return $this->value === self::GENESIS;
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->value, $other->value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
