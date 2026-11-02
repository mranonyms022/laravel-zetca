<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Events;

/** ZATCA accepted the document (REPORTED / CLEARED, possibly with warnings). */
final class InvoiceAccepted
{
    public function __construct(
        public readonly int $invoiceId,
        public readonly string $publicId,
        public readonly int $issuanceId,
        public readonly string $workflow,
        public readonly string $status,
        public readonly bool $withWarnings,
        /** The host application's record (`source` option): morph class and key. */
        public readonly ?string $sourceType = null,
        public readonly ?string $sourceId = null,
    ) {
    }
}
