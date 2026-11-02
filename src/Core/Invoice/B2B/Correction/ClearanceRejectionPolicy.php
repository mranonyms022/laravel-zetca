<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Invoice\B2B\Correction;

use CodingTriplets\Zatca\Core\Invoice\Shared\Correction\RejectedInvoicePolicy;

/**
 * B2B (standard, cleared) reissue after NOT_CLEARED.
 * Defaults: keep the IRN ⚠ (forum answers conflict), use the reissue time as the issue date ⚠ (a non-cleared
 * invoice is not a valid tax invoice), no credit note for a rejected invoice ⚠.
 */
final class ClearanceRejectionPolicy implements RejectedInvoicePolicy
{
    public function __construct(
        private readonly bool $keepInvoiceNumber = true,
        private readonly bool $updateIssueDate = true,
        private readonly bool $creditNoteForRejected = false,
    ) {
    }

    public function keepInvoiceNumber(): bool
    {
        return $this->keepInvoiceNumber;
    }

    public function issueDate(\DateTimeImmutable $rejectedIssuedAt, \DateTimeImmutable $now): \DateTimeImmutable
    {
        return $this->updateIssueDate ? $now : $rejectedIssuedAt;
    }

    public function requiresCreditNote(): bool
    {
        return $this->creditNoteForRejected;
    }
}
