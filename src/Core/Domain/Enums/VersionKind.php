<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Domain\Enums;

enum VersionKind: string
{
    case Original = 'original';
    case Correction = 'correction';
}
