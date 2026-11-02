<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Domain\ValueObjects;

use CodingTriplets\Zatca\Core\Domain\Enums\BuyerIdScheme;
use CodingTriplets\Zatca\Core\Domain\Exceptions\InvalidValue;

/**
 * Other buyer ID (BT-46) with its scheme (BT-46-1). Used when the buyer has no VAT number (BR-KSA-81),
 * e.g. an individual identified by national ID (NAT) or Iqama (IQA).
 */
final class BuyerIdentification
{
    private function __construct(
        public readonly BuyerIdScheme $scheme,
        public readonly string $value,
    ) {
    }

    public static function of(BuyerIdScheme|string $scheme, string $value): self
    {
        if (is_string($scheme)) {
            $resolved = BuyerIdScheme::tryFrom(strtoupper(trim($scheme)));
            if ($resolved === null) {
                throw InvalidValue::for('buyer.identification.scheme', "unsupported scheme [{$scheme}]", 'BR-KSA-14');
            }
            $scheme = $resolved;
        }

        $value = trim($value);

        if ($value === '' || preg_match('/^[a-zA-Z0-9]+$/', $value) !== 1) {
            throw InvalidValue::for('buyer.identification.value', 'must be non-empty and alphanumeric only', 'BR-KSA-14');
        }

        return new self($scheme, $value);
    }

    /**
     * Picks the single ID to write when several are known, following the BR-KSA-14 sequence.
     *
     * @param list<self> $candidates
     */
    public static function preferred(array $candidates): ?self
    {
        if ($candidates === []) {
            return null;
        }

        usort($candidates, static fn (self $a, self $b): int => $a->scheme->rank() <=> $b->scheme->rank());

        return $candidates[0];
    }
}
