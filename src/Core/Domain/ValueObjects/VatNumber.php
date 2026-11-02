<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Domain\ValueObjects;

use CodingTriplets\Zatca\Core\Domain\Exceptions\InvalidValue;

/**
 * KSA VAT registration number (BT-31 seller / BT-48 buyer, CSR organizationIdentifier).
 * 15 digits, first and last digit "3" (BR-KSA-40 / BR-KSA-44).
 * The 11th digit "1" identifies a VAT group (Security Features Standard, Table 1).
 */
final class VatNumber
{
    private function __construct(public readonly string $value)
    {
    }

    public static function fromString(string $value, string $field = 'vat_number'): self
    {
        $value = trim($value);

        if (preg_match('/^3\d{13}3$/', $value) !== 1) {
            throw InvalidValue::for($field, 'must be 15 digits starting and ending with 3', 'BR-KSA-40');
        }

        return new self($value);
    }

    public static function isValid(string $value): bool
    {
        return preg_match('/^3\d{13}3$/', trim($value)) === 1;
    }

    public function isGroup(): bool
    {
        return $this->value[10] === '1';
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
