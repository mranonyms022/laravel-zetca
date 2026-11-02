<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Events;

/** B2C: the 24-hour reporting deadline passed before ZATCA accepted the document. Reporting is still attempted. */
final class InvoiceReportingDeadlineBreached
{
    public function __construct(
        public readonly int $invoiceId,
        public readonly string $publicId,
        public readonly int $issuanceId,
        public readonly string $deadlineAt,
    ) {
    }
}
