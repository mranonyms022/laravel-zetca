<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Invoices;

use CodingTriplets\Zatca\Core\Domain\Enums\Workflow;
use CodingTriplets\Zatca\Laravel\Database\Models\Device;

/**
 * Builds the payload the generator sees from the stored (never modified) client payload:
 *  - the seller block always comes from the registered taxpayer data of the issuing device
 *    (a client cannot issue in another seller's name);
 *  - workflow is the invoice's immutable workflow;
 *  - identity fields (uuid, icv, pih, hash) sent by a client are ignored: they are server-assigned.
 */
final class PayloadPreparer
{
    private const IDENTITY_FIELDS = ['uuid', 'icv', 'counter', 'pih', 'previous_invoice_hash', 'invoice_hash', 'hash', 'qr', 'device_id', 'device_uuid', 'external_invoice_id'];

    /** @var array<int, array{at: float, seller: array<string, mixed>}> */
    private array $sellers = [];

    public function __construct(private readonly SellerResolver $resolver = new SellerResolver())
    {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function prepare(array $payload, Workflow $workflow, Device $device, ?string $invoiceNumber = null): array
    {
        foreach (self::IDENTITY_FIELDS as $field) {
            unset($payload[$field]);
        }

        $payload['workflow'] = $workflow->value;
        $payload['seller'] = $this->seller($device);
        if ($invoiceNumber !== null) {
            $payload['invoice_number'] = $invoiceNumber;
        }

        return $payload;
    }

    /** @return array<string, mixed> cached for 60 s per process (registration data changes rarely) */
    private function seller(Device $device): array
    {
        $cached = $this->sellers[(int) $device->id] ?? null;
        if ($cached !== null && microtime(true) - $cached['at'] < 60) {
            return $cached['seller'];
        }

        $seller = $this->resolver->forDevice($device);
        $this->sellers[(int) $device->id] = ['at' => microtime(true), 'seller' => $seller];

        return $seller;
    }
}
