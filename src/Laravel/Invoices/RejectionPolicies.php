<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Invoices;

use CodingTriplets\Zatca\Core\Domain\Enums\Workflow;
use CodingTriplets\Zatca\Core\Invoice\B2B\Correction\ClearanceRejectionPolicy;
use CodingTriplets\Zatca\Core\Invoice\B2C\Correction\ReportingRejectionPolicy;
use CodingTriplets\Zatca\Core\Invoice\Shared\Correction\RejectedInvoicePolicy;

/** The rejected-invoice policy of each workflow, from `zatca.correction.*`. */
final class RejectionPolicies
{
    public function for(Workflow $workflow): RejectedInvoicePolicy
    {
        $c = (array) config('zatca.correction', []);

        return $workflow === Workflow::B2B
            ? new ClearanceRejectionPolicy(
                (bool) ($c['b2b_keep_invoice_number'] ?? true),
                (bool) ($c['b2b_update_issue_date'] ?? true),
                (bool) ($c['credit_note_for_rejected'] ?? false),
            )
            : new ReportingRejectionPolicy(
                (bool) ($c['b2c_keep_invoice_number'] ?? true),
                (bool) ($c['b2c_keep_issue_date'] ?? true),
                (bool) ($c['credit_note_for_rejected'] ?? false),
            );
    }
}
