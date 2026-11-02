<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Invoice\Shared\Validation;

use CodingTriplets\Zatca\Core\Domain\Exceptions\ZatcaException;

/** Local validation failed: nothing was signed and no ICV was used. */
final class InvoiceValidationException extends ZatcaException
{
    public ValidationResult $result;

    public static function fromResult(ValidationResult $result): self
    {
        $first = $result->errors()[0] ?? ['field' => '', 'code' => '', 'message' => 'invalid invoice'];
        $count = count($result->errors());

        $e = self::withContext(
            "Invoice validation failed ({$count} error(s)): [{$first['code']}] {$first['field']}: {$first['message']}",
            ['errors' => $result->errors(), 'warnings' => $result->warnings()],
        );
        $e->result = $result;

        return $e;
    }
}
