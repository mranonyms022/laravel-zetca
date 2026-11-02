<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Domain\Enums;

/**
 * Fatoora environments. Credentials, counters and hash chains are never shared between them.
 */
enum Environment: string
{
    case Sandbox = 'sandbox';        // developer portal
    case Simulation = 'simulation';
    case Production = 'production';

    /** Certificate template name extension (1.3.6.1.4.1.311.20.2). Confirm in sandbox (ARCHITECTURE 2.1). */
    public function certificateTemplateName(): string
    {
        return match ($this) {
            self::Sandbox => 'TSTZATCA-Code-Signing',
            self::Simulation => 'PREZATCA-Code-Signing',
            self::Production => 'ZATCA-Code-Signing',
        };
    }

    public function isProduction(): bool
    {
        return $this === self::Production;
    }
}
