<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Invoices;

use CodingTriplets\Zatca\Core\Domain\Exceptions\ZatcaException;

/** The external invoice ID was already received with a different payload. Nothing was created. */
final class DuplicateInvoice extends ZatcaException
{
    public string $publicId = '';

    public static function differentPayload(string $externalId, string $publicId): self
    {
        $e = self::withContext("Invoice [{$externalId}] was already received with a different payload (invoice {$publicId}).", ['public_id' => $publicId]);
        $e->publicId = $publicId;

        return $e;
    }
}
