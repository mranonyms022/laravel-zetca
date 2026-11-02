<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Domain\Enums;

enum CertificateStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Superseded = 'superseded';
    case Revoked = 'revoked';
    case Expired = 'expired';
}
