<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Domain\Enums;

enum TenancyMode: string
{
    case None = 'none';
    case Column = 'column';
    case Database = 'database';
}
