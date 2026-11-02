<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Device;

use CodingTriplets\Zatca\Core\Domain\Enums\Workflow;
use CodingTriplets\Zatca\Core\Domain\ValueObjects\InvoiceTypeFlags;

/**
 * Sample documents a device must get accepted by ZATCA's compliance-check endpoint before a production CSID
 * is issued: invoice, credit note and debit note for each workflow the device is registered for (TSCZ flags).
 * The samples use the taxpayer's real seller data; they are never part of the production invoice chain.
 */
final class ComplianceSampleFactory
{
    /**
     * @param array<string, mixed> $seller payload "seller" block (name, vat_number, id_scheme, id_value, address)
     * @return array<string, array<string, mixed>> check name => invoice payload, in submission order
     */
    public function samples(InvoiceTypeFlags $flags, array $seller, \DateTimeImmutable $issuedAt): array
    {
        $samples = [];
        foreach ([[Workflow::B2B, 'standard'], [Workflow::B2C, 'simplified']] as [$workflow, $label]) {
            if (! $flags->supports($workflow)) {
                continue;
            }
            foreach (['invoice', 'credit_note', 'debit_note'] as $type) {
                $samples["{$label}_{$type}"] = $this->payload($workflow, $type, $seller, $issuedAt, count($samples) + 1);
            }
        }

        return $samples;
    }

    /** @param array<string, mixed> $seller @return array<string, mixed> */
    private function payload(Workflow $workflow, string $type, array $seller, \DateTimeImmutable $issuedAt, int $n): array
    {
        $payload = [
            'workflow' => $workflow->value,
            'type' => $type,
            'invoice_number' => sprintf('COMPLIANCE-%s-%02d', strtoupper($workflow->value), $n),
            'issued_at' => $issuedAt->format('Y-m-d\TH:i:s'),
            'currency' => 'SAR',
            'payment_means_code' => '10',
            'seller' => $seller,
            'lines' => [
                ['name' => 'Compliance check item', 'quantity' => '2', 'unit_price' => '50.00', 'vat_category' => 'S', 'vat_rate' => '15'],
            ],
        ];

        if ($workflow === Workflow::B2B) {
            $payload['buyer'] = [
                'name' => 'Compliance Check Buyer LTD',
                'vat_number' => self::buyerVatNumber((string) ($seller['vat_number'] ?? '')),
                'address' => [
                    'street' => 'King Fahd Road', 'building_number' => '1234', 'district' => 'Al Olaya',
                    'city' => 'Riyadh', 'postal_code' => '12211', 'country' => 'SA',
                ],
            ];
            $payload['supply_date'] = $issuedAt->format('Y-m-d');
        }

        if ($type !== 'invoice') {
            $payload['billing_reference'] = sprintf('COMPLIANCE-%s-%02d', strtoupper($workflow->value), $n - 1 - ($type === 'debit_note' ? 1 : 0));
            $payload['reason'] = $type === 'credit_note' ? 'Returned goods' : 'Price adjustment';
        }

        return $payload;
    }

    /** A syntactically valid VAT number that differs from the seller's (BR-KSA-44 / buyer ≠ seller). */
    private static function buyerVatNumber(string $sellerVat): string
    {
        $candidate = '399999999800003';

        return $candidate === $sellerVat ? '399999999700003' : $candidate;
    }
}
