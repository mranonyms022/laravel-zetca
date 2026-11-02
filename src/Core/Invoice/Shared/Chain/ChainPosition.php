<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Invoice\Shared\Chain;

use CodingTriplets\Zatca\Core\Domain\ValueObjects\InvoiceHash;

/**
 * Position of a document in a device's chain: invoice counter value (KSA-16) and previous invoice hash (KSA-13).
 * Allocated under the device lock by the persistence layer; never reused once a document was signed with it.
 */
final class ChainPosition
{
    public function __construct(
        public readonly int $counter,
        public readonly InvoiceHash $previousHash,
    ) {
        if ($counter < 1) {
            throw new \InvalidArgumentException('Invoice counter value must be a positive integer.');
        }
    }

    public static function first(): self
    {
        return new self(1, InvoiceHash::genesis());
    }
}
