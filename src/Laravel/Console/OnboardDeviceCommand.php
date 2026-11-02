<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Console;

use CodingTriplets\Zatca\Core\Domain\Exceptions\ZatcaException;
use CodingTriplets\Zatca\Laravel\Database\Models\Device;
use CodingTriplets\Zatca\Laravel\Devices\DeviceOnboardingService;
use Illuminate\Console\Command;

final class OnboardDeviceCommand extends Command
{
    protected $signature = 'zatca:device:onboard
        {device : Device ID}
        {--resume : Continue an onboarding that stopped after the compliance CSID (no OTP needed)}';

    protected $description = 'Onboard a device with ZATCA (key, CSR, compliance CSID, compliance checks, production CSID)';

    public function handle(DeviceOnboardingService $service): int
    {
        $device = Device::query()->findOrFail((int) $this->argument('device'));

        try {
            if ($this->option('resume')) {
                $onboarding = $service->resume($device);
            } else {
                // Read interactively (hidden input) so the OTP never lands in shell history or process lists.
                $otp = (string) $this->secret('OTP from the Fatoora portal (6 digits)');
                $onboarding = $service->onboard($device, $otp);
                unset($otp);
            }
        } catch (ZatcaException|\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $device->refresh();
        $this->line("Onboarding #{$onboarding->id}: {$onboarding->status}" . ($onboarding->current_step ? " (step: {$onboarding->current_step})" : ''));
        $this->line("Device #{$device->id}: {$device->lifecycle_status->value}");

        if ($onboarding->error) {
            $this->error((string) $onboarding->error);
        }
        if ($onboarding->status === DeviceOnboardingService::STATUS_AWAITING_CHECKS) {
            $this->warn('Compliance checks are pending. They run automatically once the invoice engine is installed (M4); then run with --resume.');
        }
        if ($device->certificate_expiry !== null) {
            $this->info('Production certificate valid until ' . $device->certificate_expiry->toDateTimeString() . ' UTC.');
        }

        return $onboarding->status === DeviceOnboardingService::STATUS_FAILED ? self::FAILURE : self::SUCCESS;
    }
}
