<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Invoice\Shared\DTOs;

use CodingTriplets\Zatca\Core\Domain\Enums\DocumentType;
use CodingTriplets\Zatca\Core\Domain\Enums\Workflow;
use CodingTriplets\Zatca\Core\Invoice\Shared\Calculation\Amount;

/**
 * Canonical, validated invoice content (before chain position and signing).
 * Produced by InvoicePayloadMapper; consumed by the B2B or B2C generator.
 */
final class InvoiceDocument
{
    /**
     * @param list<InvoiceLine>     $lines
     * @param list<AllowanceCharge> $allowanceCharges
     * @param list<string>          $billingReferences BT-25 (credit/debit notes)
     * @param list<string>          $notes             BT-22
     */
    public function __construct(
        public readonly Workflow $workflow,
        public readonly DocumentType $type,
        public readonly string $subtypeFlags,          // KSA-2, e.g. 0100000 / 0200000
        public readonly string $invoiceNumber,
        public readonly \DateTimeImmutable $issuedAt,  // Asia/Riyadh local time
        public readonly string $currency,
        public readonly Party $seller,
        public readonly ?Party $buyer,
        public readonly array $lines,
        public readonly array $allowanceCharges,
        public readonly array $billingReferences,
        public readonly ?string $reason,              // KSA-10 (credit/debit note reason)
        public readonly string $paymentMeansCode,
        public readonly ?\DateTimeImmutable $supplyDate,
        public readonly ?\DateTimeImmutable $supplyEndDate,
        public readonly Amount $prepaidAmount,
        public readonly array $notes = [],
    ) {
    }
}
