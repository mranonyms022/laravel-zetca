<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Tests\Live;

use CodingTriplets\Zatca\Core\Domain\Enums\DeviceLifecycleStatus;
use CodingTriplets\Zatca\Laravel\Devices\CertificateRenewalService;
use CodingTriplets\Zatca\Laravel\Devices\DeviceOnboardingService;
use CodingTriplets\Zatca\Laravel\Devices\DeviceRegistrar;
use CodingTriplets\Zatca\Tests\Integration\IntegrationTestCase;

/**
 * Real calls to ZATCA's developer-portal sandbox (test data only). Opt-in:
 *   ZATCA_LIVE_SANDBOX=1 [ZATCA_CA_BUNDLE=/path/ca.pem] vendor/bin/phpunit --testsuite Live
 * The developer portal accepts the published test OTP 123345.
 */
final class SandboxOnboardingTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        if (getenv('ZATCA_LIVE_SANDBOX') !== '1') {
            $this->markTestSkipped('Set ZATCA_LIVE_SANDBOX=1 to call the ZATCA developer portal.');
        }

        parent::setUp();
        config([
            'zatca.environment' => 'sandbox',
            'zatca.devices.sandbox_skip_compliance_checks' => true,
            'zatca.security.ca_bundle' => getenv('ZATCA_CA_BUNDLE') ?: null,
        ]);
    }

    public function test_onboard_and_renew_against_developer_portal(): void
    {
        $registrar = app(DeviceRegistrar::class);
        $registration = $registrar->registerTaxpayer([
            'name_en' => 'Maximum Speed Tech Supply',
            'legal_name' => 'Maximum Speed Tech Supply LTD',
            'vat_number' => '399999999900003',
        ]);
        $branch = $registrar->registerBranch((int) $registration->company_id, 'RYD', 'Riyadh Branch');
        $device = $registrar->registerDevice([
            'vat_registration_id' => $registration->id,
            'branch_id' => $branch->id,
            'name' => 'TST-886431145-399999999900003',
            'invoice_type_flags' => '1100',
            'industry' => 'Supply activities',
            'location' => 'RRRD2929',
        ]);

        $onboarding = app(DeviceOnboardingService::class)->onboard($device, '123345');
        self::assertSame(DeviceOnboardingService::STATUS_COMPLETED, $onboarding->status, (string) $onboarding->error);
        self::assertSame(DeviceLifecycleStatus::Active, $device->refresh()->lifecycle_status);

        $renewal = app(CertificateRenewalService::class)->renew($device, '123345');
        fwrite(STDERR, "\n[live] renewal: {$renewal->status} " . ($renewal->error ?? '') . "\n");
        self::assertSame(CertificateRenewalService::STATUS_COMPLETED, $renewal->status, (string) $renewal->error);
    }
}
