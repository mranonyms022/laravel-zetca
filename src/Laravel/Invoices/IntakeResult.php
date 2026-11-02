<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Invoices;

use CodingTriplets\Zatca\Core\Invoice\Shared\Validation\ValidationResult;
use CodingTriplets\Zatca\Laravel\Database\Models\Invoice;

final class IntakeResult
{
    public function __construct(
        public readonly Invoice $invoice,
        /** false when the external invoice ID was already known (idempotent replay) */
        public readonly bool $created,
        public readonly ?ValidationResult $validation = null,
    ) {
    }
}
