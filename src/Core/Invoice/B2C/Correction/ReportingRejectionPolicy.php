<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Invoice\B2C\Correction;

use CodingTriplets\Zatca\Core\Invoice\Shared\Correction\RejectedInvoicePolicy;

/**
 * B2C (simplified, reported) reissue after NOT_REPORTED.
 * Defaults: keep the IRN and the original issue date, because the sale already happened ⚠; no credit note for a
 * rejected invoice ⚠.
 */
final class ReportingRejectionPolicy implements RejectedInvoicePolicy
{
    public function __construct(
        private readonly bool $keepInvoiceNumber = true,
        private readonly bool $keepIssueDate = true,
        private readonly bool $creditNoteForRejected = false,
    ) {
    }

    public function keepInvoiceNumber(): bool
    {
        return $this->keepInvoiceNumber;
    }

    public function issueDate(\DateTimeImmutable $rejectedIssuedAt, \DateTimeImmutable $now): \DateTimeImmutable
    {
        return $this->keepIssueDate ? $rejectedIssuedAt : $now;
    }

    public function requiresCreditNote(): bool
    {
        return $this->creditNoteForRejected;
    }
}
