<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Events;

/** B2C: the document is not reported yet and its reporting deadline is near (fires once per configured threshold). */
final class InvoiceReportingDeadlineAtRisk
{
    public function __construct(
        public readonly int $invoiceId,
        public readonly string $publicId,
        public readonly int $issuanceId,
        public readonly string $deadlineAt,
        public readonly int $hoursLeft,
        /** The host application's record (`source` option): morph class and key. */
        public readonly ?string $sourceType = null,
        public readonly ?string $sourceId = null,
    ) {
    }
}
