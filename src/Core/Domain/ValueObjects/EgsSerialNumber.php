<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Domain\ValueObjects;

use CodingTriplets\Zatca\Core\Domain\Exceptions\InvalidValue;

/**
 * EGS serial number written into the CSR SAN (SN):
 * "1-<manufacturer or solution provider>|2-<model or version>|3-<serial number>".
 */
final class EgsSerialNumber
{
    private const PATTERN = '/^1-[^|]+\|2-[^|]+\|3-[^|]+$/';

    private function __construct(public readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $value = trim($value);

        if (preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidValue::for('serial_number', 'must use the format 1-<provider>|2-<model/version>|3-<serial>');
        }

        return new self($value);
    }

    public static function compose(string $provider, string $modelOrVersion, string $serial): self
    {
        foreach (['provider' => $provider, 'model' => $modelOrVersion, 'serial' => $serial] as $name => $part) {
            if ($part === '' || str_contains($part, '|')) {
                throw InvalidValue::for("serial_number.{$name}", 'must be non-empty and must not contain "|"');
            }
        }

        return new self("1-{$provider}|2-{$modelOrVersion}|3-{$serial}");
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
