<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Console;

use CodingTriplets\Zatca\Core\Domain\Exceptions\ZatcaException;
use CodingTriplets\Zatca\Laravel\Devices\DeviceRegistrar;
use Illuminate\Console\Command;

final class RegisterDeviceCommand extends Command
{
    protected $signature = 'zatca:device:register
        {--vat-registration= : VAT registration ID}
        {--branch= : Branch ID (required unless VAT group)}
        {--name= : Device name or asset number (CSR common name)}
        {--types=1100 : Invoice types TSCZ: 1000 = B2B only, 0100 = B2C only, 1100 = both}
        {--environment= : sandbox | simulation | production (default: config)}
        {--industry= : Industry / business category}
        {--location= : Branch address or website}
        {--serial= : EGS serial "1-..|2-..|3-.." (default: generated)}';

    protected $description = 'Register an EGS device (invoicing unit) before onboarding';

    public function handle(DeviceRegistrar $registrar): int
    {
        try {
            $device = $registrar->registerDevice([
                'vat_registration_id' => (int) ($this->option('vat-registration') ?: $this->ask('VAT registration ID')),
                'branch_id' => $this->option('branch') !== null ? (int) $this->option('branch') : null,
                'name' => (string) ($this->option('name') ?: $this->ask('Device name / asset number')),
                'invoice_type_flags' => (string) $this->option('types'),
                'environment' => $this->option('environment') ?: (string) config('zatca.environment'),
                'industry' => (string) ($this->option('industry') ?: $this->ask('Industry')),
                'location' => (string) ($this->option('location') ?: $this->ask('Location (address or website)')),
                'serial_number' => $this->option('serial') ?: null,
            ]);
        } catch (ZatcaException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Device #{$device->id} registered ({$device->serial_number}). Next: php artisan zatca:device:onboard {$device->id}");

        return self::SUCCESS;
    }
}
