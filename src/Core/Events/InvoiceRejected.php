<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Events;

/** ZATCA rejected the document (NOT_REPORTED / NOT_CLEARED). Needs correction (credit/debit note or correct-and-reprocess). */
final class InvoiceRejected
{
    public function __construct(
        public readonly int $invoiceId,
        public readonly string $publicId,
        public readonly int $issuanceId,
        public readonly string $workflow,
        public readonly string $status,
        /** The host application's record (`source` option): morph class and key. */
        public readonly ?string $sourceType = null,
        public readonly ?string $sourceId = null,
    ) {
    }
}
