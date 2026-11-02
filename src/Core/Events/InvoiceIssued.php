<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Events;

/** Fired after the issuance transaction commits: the document is signed, has its ICV/PIH and (B2C) a QR code. */
final class InvoiceIssued
{
    public function __construct(
        public readonly int $invoiceId,
        public readonly string $publicId,
        public readonly int $issuanceId,
        public readonly string $workflow,
        public readonly int $counter,
        public readonly string $uuid,
    ) {
    }
}
