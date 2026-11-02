<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Domain\ValueObjects;

use CodingTriplets\Zatca\Core\Domain\Exceptions\InvalidValue;

/** 10-digit Tax Identification Number (e.g. VAT group member, CSR OU for groups). */
final class Tin
{
    private function __construct(public readonly string $value)
    {
    }

    public static function fromString(string $value, string $field = 'tin'): self
    {
        $value = trim($value);

        if (preg_match('/^\d{10}$/', $value) !== 1) {
            throw InvalidValue::for($field, 'must be a 10-digit TIN');
        }

        return new self($value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
