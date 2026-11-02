<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Domain\Enums;

enum IssuanceStatus: string
{
    case Issued = 'issued';
    case Accepted = 'accepted';
    case AcceptedWithWarnings = 'accepted_with_warnings';
    case Rejected = 'rejected';
    case Superseded = 'superseded';

    public function isAccepted(): bool
    {
        return $this === self::Accepted || $this === self::AcceptedWithWarnings;
    }
}
