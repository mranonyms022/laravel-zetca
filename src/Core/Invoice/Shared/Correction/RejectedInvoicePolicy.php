<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Invoice\Shared\Correction;

/**
 * What a corrected (re-issued) rejected document keeps from the rejected one (ARCHITECTURE 3.2).
 *
 * Fixed by ZATCA's rules, not configurable here: a reissue always gets a new UUID, the next ICV and the current
 * chain-head PIH, and the rejected document stays in the chain. The configurable points below are not stated
 * in the official guideline and are marked ⚠ there.
 */
interface RejectedInvoicePolicy
{
    /** Keep the invoice number (IRN, cbc:ID) of the rejected document? ⚠ */
    public function keepInvoiceNumber(): bool;

    /** Issue date/time of the reissued document, given the rejected document's and the current time. ⚠ */
    public function issueDate(\DateTimeImmutable $rejectedIssuedAt, \DateTimeImmutable $now): \DateTimeImmutable;

    /** Must a rejected (never accepted) document be cancelled with a credit note instead of being corrected? ⚠ */
    public function requiresCreditNote(): bool;
}
