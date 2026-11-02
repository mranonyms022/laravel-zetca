<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Console;

use CodingTriplets\Zatca\Core\Domain\Exceptions\ZatcaException;
use CodingTriplets\Zatca\Laravel\Database\Models\Device;
use CodingTriplets\Zatca\Laravel\Devices\CertificateRenewalService;
use Illuminate\Console\Command;

final class RenewDeviceCommand extends Command
{
    protected $signature = 'zatca:device:renew {device : Device ID}';

    protected $description = 'Renew a device\'s production certificate (new key + CSR, needs a new OTP)';

    public function handle(CertificateRenewalService $service): int
    {
        $device = Device::query()->findOrFail((int) $this->argument('device'));

        try {
            $otp = (string) $this->secret('OTP from the Fatoora portal (6 digits)');
            $renewal = $service->renew($device, $otp);
            unset($otp);
        } catch (ZatcaException|\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $device->refresh();
        $this->line("Renewal #{$renewal->id}: {$renewal->status}; device #{$device->id}: {$device->lifecycle_status->value}");

        if ($renewal->error) {
            $this->error((string) $renewal->error);

            return self::FAILURE;
        }

        $this->info('New certificate valid until ' . $device->certificate_expiry?->toDateTimeString() . ' UTC.');

        return self::SUCCESS;
    }
}
