<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Invoice\Shared\DTOs;

/** Seller (BG-4) or buyer (BG-7). */
final class Party
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $vatNumber,
        public readonly ?string $idScheme,
        public readonly ?string $idValue,
        public readonly Address $address,
    ) {
    }
}
