<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Tests\Integration\Devices;

use CodingTriplets\Zatca\Core\Contracts\Device\ComplianceCheckRunner;
use CodingTriplets\Zatca\Core\Contracts\Fatoora\CsidCredentials;
use CodingTriplets\Zatca\Core\Domain\Enums\CertificateStatus;
use CodingTriplets\Zatca\Core\Domain\Enums\CertificateType;
use CodingTriplets\Zatca\Core\Domain\Enums\DeviceLifecycleStatus;
use CodingTriplets\Zatca\Core\Domain\Exceptions\DeviceStateException;
use CodingTriplets\Zatca\Core\Domain\Exceptions\InvalidValue;
use CodingTriplets\Zatca\Core\Events\CertificateExpiring;
use CodingTriplets\Zatca\Core\Events\DeviceOnboarded;
use CodingTriplets\Zatca\Core\Fatoora\BasicAuthentication;
use CodingTriplets\Zatca\Laravel\Audit\AuditLogger;
use CodingTriplets\Zatca\Laravel\Database\Models\AuditLog;
use CodingTriplets\Zatca\Laravel\Database\Models\Certificate;
use CodingTriplets\Zatca\Laravel\Database\Models\Device;
use CodingTriplets\Zatca\Laravel\Database\Schema\Tables;
use CodingTriplets\Zatca\Laravel\Devices\CertificateExpiryMonitor;
use CodingTriplets\Zatca\Laravel\Devices\CertificateRenewalService;
use CodingTriplets\Zatca\Laravel\Devices\DeviceCredentialStore;
use CodingTriplets\Zatca\Laravel\Devices\DeviceOnboardingService;
use CodingTriplets\Zatca\Laravel\Devices\DeviceRegistrar;
use CodingTriplets\Zatca\Laravel\Fatoora\FatooraClientFactory;
use CodingTriplets\Zatca\Tests\Integration\IntegrationTestCase;
use CodingTriplets\Zatca\Tests\Support\FakeZatca;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

final class DeviceLifecycleTest extends IntegrationTestCase
{
    private const OTP = '987654';

    private FakeZatca $zatca;

    protected function setUp(): void
    {
        parent::setUp();

        $this->zatca = new FakeZatca();
        $this->app->instance(FatooraClientFactory::class, new FatooraClientFactory(new BasicAuthentication(), $this->zatca->stack));
    }

    public function test_full_sandbox_onboarding_activates_device_and_protects_secrets(): void
    {
        config(['zatca.devices.sandbox_skip_compliance_checks' => true]);
        Event::fake([DeviceOnboarded::class]);
        $this->zatca->csidIssued('1234567890123', 'compliance-secret')->csidIssued('30368', 'production-secret');
        $device = $this->registerDevice();

        $onboarding = app(DeviceOnboardingService::class)->onboard($device, self::OTP);

        $device->refresh();
        self::assertSame(DeviceOnboardingService::STATUS_COMPLETED, $onboarding->status);
        self::assertSame(DeviceLifecycleStatus::Active, $device->lifecycle_status);
        self::assertTrue($device->lifecycle_status->canIssue());
        self::assertNotNull($device->certificate_expiry);

        $production = Certificate::query()->findOrFail($device->active_certificate_id);
        self::assertSame(CertificateType::Production, $production->type);
        self::assertSame('production-secret', app(DeviceCredentialStore::class)->credentials($production)->secret());

        // Production CSID was requested with the compliance CSID, using the compliance request ID.
        self::assertSame('Basic ' . base64_encode(FakeZatca::binarySecurityToken() . ':compliance-secret'), $this->zatca->request(1)->getHeaderLine('Authorization'));
        self::assertSame(['compliance_request_id' => '1234567890123'], json_decode((string) $this->zatca->request(1)->getBody(), true));

        // The CSR sent to ZATCA is the stored one and carries the device's data.
        $csr = base64_decode(json_decode((string) $this->zatca->request(0)->getBody(), true)['csr']);
        self::assertSame($production->deviceKey->csr, $csr);
        self::assertSame('POS-RYD-01', openssl_csr_get_subject($csr)['CN']);

        // No secret, private key or OTP is stored in plaintext anywhere.
        $dump = $this->databaseDump();
        foreach (['compliance-secret', 'production-secret', self::OTP, 'BEGIN EC PRIVATE KEY', 'BEGIN PRIVATE KEY'] as $needle) {
            self::assertStringNotContainsString($needle, $dump, "Found [{$needle}] in the database");
        }
        self::assertArrayNotHasKey('encrypted_secret', $production->toArray());

        Event::assertDispatched(DeviceOnboarded::class, fn (DeviceOnboarded $e) => $e->deviceId === (int) $device->id);
        self::assertTrue(app(AuditLogger::class)->verifyChain()['valid']);
        self::assertTrue(AuditLog::query()->where('action', 'device.onboarded')->exists());
    }

    public function test_onboarding_waits_for_compliance_checks_outside_the_developer_sandbox(): void
    {
        $this->zatca->csidIssued();
        $device = $this->registerDevice('simulation');

        $onboarding = app(DeviceOnboardingService::class)->onboard($device, self::OTP);

        self::assertSame(DeviceOnboardingService::STATUS_AWAITING_CHECKS, $onboarding->status);
        self::assertSame(DeviceLifecycleStatus::ComplianceCsidIssued, $device->refresh()->lifecycle_status);
        self::assertCount(1, $this->zatca->history, 'Production CSID must not be requested before compliance checks pass');
    }

    public function test_resume_runs_compliance_checks_then_requests_production_csid(): void
    {
        $this->zatca->csidIssued()->csidIssued('30368');
        $device = $this->registerDevice('simulation');
        app(DeviceOnboardingService::class)->onboard($device, self::OTP);

        $this->app->instance(ComplianceCheckRunner::class, new class () implements ComplianceCheckRunner {
            public function run(array $device, CsidCredentials $compliance, string $privateKeyPem): array
            {
                return ['standard_invoice' => ['passed' => true, 'status' => 'CLEARED', 'errors' => []]];
            }
        });
        $onboarding = app(DeviceOnboardingService::class)->resume($device);

        self::assertSame(DeviceOnboardingService::STATUS_COMPLETED, $onboarding->status);
        self::assertSame(DeviceLifecycleStatus::Active, $device->refresh()->lifecycle_status);
        self::assertTrue($onboarding->compliance_checks['standard_invoice']['passed']);
    }

    public function test_rejected_otp_fails_onboarding_and_allows_a_new_attempt(): void
    {
        config(['zatca.devices.sandbox_skip_compliance_checks' => true]);
        $this->zatca->error(400, ['errors' => [['code' => 'Invalid-OTP', 'message' => 'The provided OTP is invalid']]]);
        $device = $this->registerDevice();

        $failed = app(DeviceOnboardingService::class)->onboard($device, '111111');

        self::assertSame(DeviceOnboardingService::STATUS_FAILED, $failed->status);
        self::assertStringContainsString('Invalid-OTP', (string) $failed->error);
        self::assertSame(DeviceLifecycleStatus::OnboardingFailed, $device->refresh()->lifecycle_status);

        $this->zatca->csidIssued()->csidIssued();
        $retry = app(DeviceOnboardingService::class)->onboard($device, self::OTP);

        self::assertSame(DeviceOnboardingService::STATUS_COMPLETED, $retry->status);
        self::assertSame(2, $device->keys()->count(), 'Each attempt uses a fresh key; old keys stay in history');
    }

    public function test_active_device_cannot_be_onboarded_again(): void
    {
        $device = $this->activeDevice();

        $this->expectException(DeviceStateException::class);
        app(DeviceOnboardingService::class)->onboard($device, self::OTP);
    }

    public function test_renewal_switches_certificate_and_keeps_history(): void
    {
        $device = $this->activeDevice();
        $old = Certificate::query()->findOrFail($device->active_certificate_id);
        $this->zatca->csidIssued('40000', 'renewed-secret');

        $renewal = app(CertificateRenewalService::class)->renew($device, self::OTP);

        $device->refresh();
        self::assertSame(CertificateRenewalService::STATUS_COMPLETED, $renewal->status);
        self::assertNotSame((int) $old->id, (int) $device->active_certificate_id);
        self::assertSame(CertificateStatus::Superseded, $old->refresh()->status);
        $new = Certificate::query()->findOrFail($device->active_certificate_id);
        self::assertSame(2, (int) $new->version);
        self::assertNotSame((int) $old->device_key_id, (int) $new->device_key_id, 'Renewal uses a new key');

        $request = $this->zatca->request(2);
        self::assertSame('PATCH', $request->getMethod());
        self::assertSame(self::OTP, $request->getHeaderLine('OTP'));
    }

    public function test_failed_renewal_keeps_the_device_issuing_with_the_old_certificate(): void
    {
        $device = $this->activeDevice();
        $old = (int) $device->active_certificate_id;
        $this->zatca->error(400, ['errors' => [['code' => 'Invalid-OTP', 'message' => 'Invalid OTP']]]);

        $renewal = app(CertificateRenewalService::class)->renew($device, '111111');

        $device->refresh();
        self::assertSame(CertificateRenewalService::STATUS_FAILED, $renewal->status);
        self::assertSame(DeviceLifecycleStatus::RenewalFailed, $device->lifecycle_status);
        self::assertTrue($device->lifecycle_status->canIssue());
        self::assertSame($old, (int) $device->active_certificate_id);
        self::assertSame(CertificateStatus::Active, Certificate::query()->findOrFail($old)->status);
    }

    public function test_expiry_monitor_flags_renewal_due_once_and_expires_devices(): void
    {
        Event::fake([CertificateExpiring::class]);
        $soon = $this->activeDevice();
        $soon->update(['certificate_expiry' => now()->addDays(10)]);
        $past = $this->activeDevice();
        $past->update(['certificate_expiry' => now()->subDay()]);

        $first = app(CertificateExpiryMonitor::class)->check();
        $second = app(CertificateExpiryMonitor::class)->check();

        self::assertSame(DeviceLifecycleStatus::RenewalDue, $soon->refresh()->lifecycle_status);
        self::assertSame(DeviceLifecycleStatus::Expired, $past->refresh()->lifecycle_status);
        self::assertFalse($past->lifecycle_status->canIssue());
        self::assertSame(1, $first['warnings']);
        self::assertSame(0, $second['warnings']);
        Event::assertDispatchedTimes(CertificateExpiring::class, 1);
    }

    public function test_registrar_enforces_vat_group_and_branch_rules(): void
    {
        $registrar = app(DeviceRegistrar::class);

        try {
            $registrar->registerTaxpayer(['name_en' => 'Group Co', 'legal_name' => 'Group Co', 'vat_number' => '399999999910003']);
            self::fail('VAT group without member TIN must be rejected');
        } catch (InvalidValue) {
        }

        $registration = $registrar->registerTaxpayer(['name_en' => 'Solo Co', 'legal_name' => 'Solo Co LLC', 'vat_number' => '300000000000003']);

        $this->expectException(InvalidValue::class);
        $registrar->registerDevice([
            'vat_registration_id' => $registration->id, 'name' => 'POS', 'invoice_type_flags' => '1100',
            'industry' => 'Retail', 'location' => 'Riyadh',
        ]);
    }

    public function test_audit_tampering_is_detected(): void
    {
        $this->registerDevice();
        self::assertTrue(app(AuditLogger::class)->verifyChain()['valid']);

        DB::table(Tables::name('audit_logs'))->where('action', 'device.registered')->update(['action' => 'device.deleted']);

        self::assertFalse(app(AuditLogger::class)->verifyChain()['valid']);
    }

    public function test_onboarding_commands_are_registered(): void
    {
        $commands = array_keys(\Illuminate\Support\Facades\Artisan::all());

        foreach (['zatca:taxpayer:register', 'zatca:device:register', 'zatca:device:onboard', 'zatca:device:renew', 'zatca:devices', 'zatca:devices:check-expiry', 'zatca:audit:verify'] as $name) {
            self::assertContains($name, $commands);
        }
    }

    private function registerDevice(string $environment = 'sandbox'): Device
    {
        static $n = 0;
        $n++;
        $registrar = app(DeviceRegistrar::class);
        $registration = $registrar->registerTaxpayer([
            'name_en' => "Maximum Speed {$n}",
            'legal_name' => 'Maximum Speed Tech Supply LTD',
            'vat_number' => '3' . str_pad((string) (100 + $n), 13, '0', STR_PAD_LEFT) . '3',
        ]);
        $branch = $registrar->registerBranch((int) $registration->company_id, 'RYD', 'Riyadh Branch');

        return $registrar->registerDevice([
            'vat_registration_id' => $registration->id,
            'branch_id' => $branch->id,
            'name' => 'POS-RYD-01',
            'invoice_type_flags' => '1100',
            'environment' => $environment,
            'industry' => 'Supply activities',
            'location' => 'RRRD2929',
        ]);
    }

    private function activeDevice(): Device
    {
        config(['zatca.devices.sandbox_skip_compliance_checks' => true]);
        $this->zatca->csidIssued()->csidIssued();
        $device = $this->registerDevice();
        app(DeviceOnboardingService::class)->onboard($device, self::OTP);

        return $device->refresh();
    }

    private function databaseDump(): string
    {
        $dump = '';
        foreach (['devices', 'device_keys', 'certificates', 'device_onboardings', 'device_renewals', 'audit_logs'] as $table) {
            $dump .= json_encode(DB::table(Tables::name($table))->get());
        }

        return $dump;
    }
}
