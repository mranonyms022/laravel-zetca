<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Invoice\Shared\Generation;

use CodingTriplets\Zatca\Core\Invoice\Shared\Calculation\InvoiceTotals;
use CodingTriplets\Zatca\Core\Invoice\Shared\DTOs\InvoiceDocument;
use CodingTriplets\Zatca\Core\Invoice\Shared\Signing\SignedInvoice;

/** Result of generating one document: signed XML, QR, hash, chain position and totals. */
final class GeneratedInvoice
{
    /** @param list<array{field: string, code: string, message: string}> $warnings */
    public function __construct(
        public readonly InvoiceDocument $document,
        public readonly InvoiceTotals $totals,
        public readonly string $uuid,
        public readonly int $counter,
        public readonly string $previousHash,
        public readonly SignedInvoice $signed,
        public readonly array $warnings = [],
    ) {
    }

    public function xml(): string
    {
        return $this->signed->xml;
    }

    public function hash(): string
    {
        return $this->signed->hash;
    }

    public function qrCode(): string
    {
        return $this->signed->qrCode;
    }
}
