<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Domain\Enums;

enum CertificateType: string
{
    case Compliance = 'compliance';
    case Production = 'production';
}
