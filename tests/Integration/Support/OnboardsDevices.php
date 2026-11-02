<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Tests\Integration\Support;

use CodingTriplets\Zatca\Laravel\Database\Models\Device;
use CodingTriplets\Zatca\Laravel\Devices\DeviceOnboardingService;
use CodingTriplets\Zatca\Laravel\Devices\DeviceRegistrar;

/** Registers a taxpayer + device and onboards it against FakeZatca with certificates that match the device key. */
trait OnboardsDevices
{
    protected function activeDevice(): Device
    {
        $registrar = app(DeviceRegistrar::class);
        $registration = $registrar->registerTaxpayer([
            'name_en' => 'Maximum Speed',
            'legal_name' => 'Maximum Speed Tech Supply LTD',
            'vat_number' => '399999999900003',
            'crn' => '1010010000',
            'address' => ['street' => 'Prince Sultan', 'building_number' => '2322', 'district' => 'Al-Murabba', 'city' => 'Riyadh', 'postal_code' => '23333', 'country' => 'SA'],
        ]);
        $branch = $registrar->registerBranch((int) $registration->company_id, 'RYD', 'Riyadh Branch');
        $device = $registrar->registerDevice([
            'vat_registration_id' => $registration->id,
            'branch_id' => $branch->id,
            'name' => 'POS-RYD-01',
            'invoice_type_flags' => '1100',
            'environment' => 'sandbox',
            'industry' => 'Supply activities',
            'location' => 'RRRD2929',
        ]);
        $this->zatca->csidForSubmittedCsr();
        app(DeviceOnboardingService::class)->onboard($device, '123456');

        return $device->refresh();
    }

}
