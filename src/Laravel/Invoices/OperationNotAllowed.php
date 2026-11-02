<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Invoices;

use CodingTriplets\Zatca\Core\Domain\Exceptions\ZatcaException;
use CodingTriplets\Zatca\Laravel\Database\Models\Invoice;

/** The requested operation (retry, correct, reissue, approve, credit/debit note) is not allowed in the invoice's state. */
final class OperationNotAllowed extends ZatcaException
{
    public static function because(Invoice $invoice, string $reason): self
    {
        return self::withContext("Invoice {$invoice->public_id}: operation not allowed, {$reason}.", ['public_id' => (string) $invoice->public_id]);
    }
}
