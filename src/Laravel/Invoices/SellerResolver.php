<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Invoices;

use CodingTriplets\Zatca\Laravel\Database\Models\Device;

/**
 * Builds the invoice "seller" block from the registered taxpayer data (VAT registration + branch address
 * override), so invoices always carry the seller identity the device was onboarded for.
 */
final class SellerResolver
{
    /** @return array<string, mixed> */
    public function forDevice(Device $device): array
    {
        $registration = $device->vatRegistration()->firstOrFail();
        $branch = $device->branch()->first();

        $address = array_filter((array) ($branch?->address_override ?: []))
            ?: array_filter((array) ($registration->address ?: []))
            ?: array_filter((array) ($registration->company()->first()?->address ?: []));

        return array_filter([
            'name' => (string) $registration->legal_name,
            'vat_number' => (string) $registration->vat_number,
            'id_scheme' => $registration->seller_id_scheme,
            'id_value' => $registration->seller_id_value,
            'address' => $address,
        ], static fn ($v) => $v !== null && $v !== '');
    }
}
