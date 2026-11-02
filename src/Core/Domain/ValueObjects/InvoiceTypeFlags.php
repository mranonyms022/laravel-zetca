<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Domain\ValueObjects;

use CodingTriplets\Zatca\Core\Domain\Enums\Workflow;
use CodingTriplets\Zatca\Core\Domain\Exceptions\InvalidValue;

/**
 * CSR "Invoice Type": 4 digits of 0/1 mapped to "TSCZ"
 * (T = standard tax invoice, S = simplified tax invoice, C and Z = reserved for future use).
 * Example: 1100 = standard and simplified. Source: Security Features Implementation Standards v1.1, Table 1.
 */
final class InvoiceTypeFlags
{
    private function __construct(public readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        if (preg_match('/^[01]{4}$/', $value) !== 1) {
            throw InvalidValue::for('invoice_type_flags', 'must be 4 digits of 0/1 mapped to TSCZ (e.g. 1100)');
        }

        if ($value[0] === '0' && $value[1] === '0') {
            throw InvalidValue::for('invoice_type_flags', 'device must support at least standard (T) or simplified (S) invoices');
        }

        return new self($value);
    }

    public static function forWorkflows(Workflow ...$workflows): self
    {
        $t = in_array(Workflow::B2B, $workflows, true) ? '1' : '0';
        $s = in_array(Workflow::B2C, $workflows, true) ? '1' : '0';

        return self::fromString($t . $s . '00');
    }

    public function supportsStandard(): bool
    {
        return $this->value[0] === '1';
    }

    public function supportsSimplified(): bool
    {
        return $this->value[1] === '1';
    }

    public function supports(Workflow $workflow): bool
    {
        return $workflow === Workflow::B2B ? $this->supportsStandard() : $this->supportsSimplified();
    }

    /** @return list<Workflow> */
    public function workflows(): array
    {
        $out = [];
        if ($this->supportsStandard()) {
            $out[] = Workflow::B2B;
        }
        if ($this->supportsSimplified()) {
            $out[] = Workflow::B2C;
        }

        return $out;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
