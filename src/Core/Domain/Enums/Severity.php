<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Domain\Enums;

enum Severity: string
{
    case Info = 'INFO';
    case Warning = 'WARNING';
    case Error = 'ERROR';
    case Critical = 'CRITICAL';

    public function weight(): int
    {
        return match ($this) {
            self::Info => 0,
            self::Warning => 1,
            self::Error => 2,
            self::Critical => 3,
        };
    }
}
