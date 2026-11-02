<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Facades;

use CodingTriplets\Zatca\Laravel\ZatcaManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static string version()
 * @method static \CodingTriplets\Zatca\Core\Domain\Enums\Environment defaultEnvironment()
 * @method static ZatcaManager auth(\Closure $callback)
 *
 * @see ZatcaManager
 */
final class Zatca extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ZatcaManager::class;
    }
}
