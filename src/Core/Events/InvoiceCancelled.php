<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Events;

/**
 * An invoice was cancelled:
 *  - kind "credit_note": an accepted invoice got a full credit note (cancel()); see cancellationPublicId;
 *  - kind "discarded":   it was never signed and was withdrawn (discard()); nothing went to ZATCA;
 *  - kind "abandoned":   ZATCA rejected it and it is no longer needed (abandon()) ⚠.
 */
final class InvoiceCancelled
{
    public function __construct(
        public readonly int $invoiceId,
        public readonly string $publicId,
        public readonly string $kind,
        public readonly string $reason,
        public readonly ?string $cancellationPublicId = null,
        public readonly ?int $actorId = null,
        /** The host application's record (`source` option): morph class and key. */
        public readonly ?string $sourceType = null,
        public readonly ?string $sourceId = null,
    ) {
    }
}
