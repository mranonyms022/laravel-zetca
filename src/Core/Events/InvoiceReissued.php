<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Events;

/** A rejected document was corrected or reissued: a new issuance (new UUID, next ICV) now replaces it. The old issuance stays in the chain. */
final class InvoiceReissued
{
    public function __construct(
        public readonly int $invoiceId,
        public readonly string $publicId,
        public readonly int $issuanceId,
        public readonly int $previousIssuanceId,
        public readonly string $workflow,
        public readonly string $reason,
        public readonly int $counter,
        public readonly string $uuid,
        public readonly ?int $actorId,
        /** The host application's record (`source` option): morph class and key. */
        public readonly ?string $sourceType = null,
        public readonly ?string $sourceId = null,
    ) {
    }
}
