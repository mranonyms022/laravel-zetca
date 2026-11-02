<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Domain\ValueObjects;

use CodingTriplets\Zatca\Core\Domain\Enums\Workflow;
use CodingTriplets\Zatca\Core\Domain\Exceptions\InvalidValue;

/**
 * Invoice transaction code KSA-2 (cbc:InvoiceTypeCode/@name), format "NNPNESB":
 *  1-2 "01" standard / "02" simplified, 3 third-party, 4 nominal, 5 exports, 6 summary, 7 self-billed.
 */
final class SubtypeFlags
{
    private function __construct(public readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        if (preg_match('/^0[12][01]{5}$/', $value) !== 1) {
            throw InvalidValue::for('subtype_flags', 'must be 7 characters: 01|02 followed by five 0/1 flags', 'KSA-2');
        }

        return new self($value);
    }

    public static function plain(Workflow $workflow): self
    {
        return new self($workflow->subtypePrefix() . '00000');
    }

    public function workflow(): Workflow
    {
        return Workflow::fromSubtypeFlags($this->value);
    }

    public function isThirdParty(): bool
    {
        return $this->value[2] === '1';
    }

    public function isNominal(): bool
    {
        return $this->value[3] === '1';
    }

    public function isExport(): bool
    {
        return $this->value[4] === '1';
    }

    public function isSummary(): bool
    {
        return $this->value[5] === '1';
    }

    public function isSelfBilled(): bool
    {
        return $this->value[6] === '1';
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
