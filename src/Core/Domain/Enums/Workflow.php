<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Domain\Enums;

/**
 * The two independent ZATCA pipelines. Fixed at intake and immutable afterwards.
 * B2B = standard tax invoices (subtype 01) cleared before sharing with the buyer.
 * B2C = simplified tax invoices (subtype 02) reported after being given to the customer.
 */
enum Workflow: string
{
    case B2B = 'b2b';
    case B2C = 'b2c';

    /** First two characters of KSA-2 (invoice transaction code). */
    public function subtypePrefix(): string
    {
        return $this === self::B2B ? '01' : '02';
    }

    public static function fromSubtypeFlags(string $flags): self
    {
        return match (substr($flags, 0, 2)) {
            '01' => self::B2B,
            '02' => self::B2C,
            default => throw new \InvalidArgumentException("Invoice subtype flags must start with 01 or 02, got [{$flags}]."),
        };
    }

    public function label(): string
    {
        return $this === self::B2B ? 'B2B - Standard (clearance)' : 'B2C - Simplified (reporting)';
    }
}
