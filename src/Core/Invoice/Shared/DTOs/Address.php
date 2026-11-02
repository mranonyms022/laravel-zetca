<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Invoice\Shared\DTOs;

/** Postal address (BG-5 / BG-8). Saudi addresses use the national address fields. */
final class Address
{
    public function __construct(
        public readonly ?string $street = null,
        public readonly ?string $buildingNumber = null,
        public readonly ?string $additionalNumber = null,
        public readonly ?string $district = null,
        public readonly ?string $city = null,
        public readonly ?string $postalCode = null,
        public readonly ?string $province = null,
        public readonly string $country = 'SA',
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $s = static fn (string ...$keys) => self::first($data, $keys);

        return new self(
            $s('street', 'street_name'),
            $s('building_number', 'building'),
            $s('additional_number', 'plot_identification'),
            $s('district', 'city_subdivision', 'neighborhood'),
            $s('city', 'city_name'),
            $s('postal_code', 'postal_zone', 'zip'),
            $s('province', 'region', 'country_subentity'),
            strtoupper($s('country', 'country_code') ?? 'SA'),
        );
    }

    /** @param array<string, mixed> $data @param list<string> $keys */
    private static function first(array $data, array $keys): ?string
    {
        foreach ($keys as $k) {
            if (isset($data[$k]) && trim((string) $data[$k]) !== '') {
                return trim((string) $data[$k]);
            }
        }

        return null;
    }
}
