<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Events;

/** Submission stopped without a ZATCA decision (attempts exhausted, authentication failure or manual review). Needs an operator. */
final class InvoiceSubmissionFailed
{
    public function __construct(
        public readonly int $invoiceId,
        public readonly string $publicId,
        public readonly int $issuanceId,
        public readonly string $workflow,
        public readonly string $reason,
        /** The host application's record (`source` option): morph class and key. */
        public readonly ?string $sourceType = null,
        public readonly ?string $sourceId = null,
    ) {
    }
}
