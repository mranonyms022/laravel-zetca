<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Domain\Enums;

enum ValidationStatus: string
{
    case Pending = 'PENDING';
    case Valid = 'VALID';
    case ValidWithWarnings = 'VALID_WITH_WARNINGS';
    case Invalid = 'INVALID';
}
