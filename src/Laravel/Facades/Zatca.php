<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Facades;

use CodingTriplets\Zatca\Laravel\ZatcaManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static string version()
 * @method static \CodingTriplets\Zatca\Core\Domain\Enums\Environment defaultEnvironment()
 * @method static ZatcaManager auth(\Closure $callback)
 * @method static \CodingTriplets\Zatca\Laravel\Invoices\InvoiceIssuer b2c()
 * @method static \CodingTriplets\Zatca\Laravel\Invoices\InvoiceIssuer b2b()
 * @method static \CodingTriplets\Zatca\Laravel\Invoices\InvoiceOperations invoices()
 * @method static \CodingTriplets\Zatca\Laravel\Devices\CompanyService companies()
 * @method static \CodingTriplets\Zatca\Laravel\Devices\DeviceService devices()
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
