<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Tests\Integration\Dashboard;

use CodingTriplets\Zatca\Core\Domain\Enums\DeviceLifecycleStatus;
use CodingTriplets\Zatca\Core\Domain\Enums\ErrorCategory;
use CodingTriplets\Zatca\Core\Domain\Enums\Severity;
use CodingTriplets\Zatca\Core\Fatoora\BasicAuthentication;
use CodingTriplets\Zatca\Laravel\Dashboard\Abilities;
use CodingTriplets\Zatca\Laravel\Database\Models\AuditLog;
use CodingTriplets\Zatca\Laravel\Database\Models\Branch;
use CodingTriplets\Zatca\Laravel\Database\Models\Certificate;
use CodingTriplets\Zatca\Laravel\Database\Models\Company;
use CodingTriplets\Zatca\Laravel\Database\Models\Device;
use CodingTriplets\Zatca\Laravel\Database\Models\DeviceOnboarding;
use CodingTriplets\Zatca\Laravel\Database\Models\ErrorGroup;
use CodingTriplets\Zatca\Laravel\Database\Models\Invoice;
use CodingTriplets\Zatca\Laravel\Database\Models\ZatcaError;
use CodingTriplets\Zatca\Laravel\Fatoora\FatooraClientFactory;
use CodingTriplets\Zatca\Laravel\Processing\ErrorRecorder;
use CodingTriplets\Zatca\Laravel\ZatcaManager;
use CodingTriplets\Zatca\Tests\Integration\IntegrationTestCase;
use CodingTriplets\Zatca\Tests\Integration\Support\OnboardsDevices;
use CodingTriplets\Zatca\Tests\Support\FakeZatca;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\UploadedFile;

/** M7b: devices (register, import, onboard), companies, errors, queue, audit, settings. */
final class DashboardOperationsTest extends IntegrationTestCase
{
    use OnboardsDevices;

    private FakeZatca $zatca;

    private Device $device;

    /** @var list<string>|null */
    private ?array $granted = null;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('zatca.ui.middleware', ['web']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['zatca.devices.sandbox_skip_compliance_checks' => true]);
        $this->zatca = new FakeZatca();
        $this->app->instance(FatooraClientFactory::class, new FatooraClientFactory(new BasicAuthentication(), $this->zatca->stack));
        $this->device = $this->activeDevice();
        app(ZatcaManager::class)->auth(fn ($user, string $ability) => $this->granted === null || in_array($ability, $this->granted, true));
    }

    // ---------------------------------------------------------------- devices

    public function test_device_pages_render_without_secrets(): void
    {
        $this->login()->get('/admin/zatca/devices')->assertOk()->assertSee('POS-RYD-01');
        $this->login()->get('/admin/zatca/devices?status=ACTIVE&environment=sandbox&q=POS')->assertOk()->assertSee('POS-RYD-01');
        $this->login()->get('/admin/zatca/devices?status=EXPIRED')->assertOk()->assertDontSee('POS-RYD-01');

        $response = $this->login()->get('/admin/zatca/devices/' . $this->device->id)->assertOk()
            ->assertSee('Certificates')->assertSee('Renew the production certificate');
        $certificate = Certificate::query()->where('device_id', $this->device->id)->firstOrFail();
        $response->assertDontSee((string) $certificate->encrypted_secret)
            ->assertDontSee('BEGIN CERTIFICATE')
            ->assertDontSee(substr((string) $certificate->certificate, 10, 40));

        $this->login()->get('/admin/zatca/devices/999999')->assertNotFound();
    }

    public function test_register_then_onboard_a_device_from_the_dashboard_without_keeping_the_otp(): void
    {
        $store = Branch::query()->firstOrFail();
        $this->login()->get('/admin/zatca/devices/new')->assertOk()->assertSee('Riyadh Branch');

        $this->login()->post('/admin/zatca/devices', [
            'company_id' => $store->company_id, 'store_id' => $store->id, 'name' => 'POS-UI-02',
            'invoice_types' => '0100', 'industry' => 'Retail', 'location' => 'Riyadh', 'environment' => 'sandbox',
        ])->assertRedirect()->assertSessionHas('zatca_status');
        $device = Device::query()->where('name', 'POS-UI-02')->firstOrFail();
        self::assertSame(DeviceLifecycleStatus::Registered, $device->lifecycle_status);

        $this->login()->post('/admin/zatca/devices/' . $device->id . '/onboard', ['otp' => '12ab'])
            ->assertSessionHas('zatca_error', 'The OTP has 6 digits.');

        $this->zatca->csidForSubmittedCsr();
        $response = $this->login()->post('/admin/zatca/devices/' . $device->id . '/onboard', ['otp' => '804217']);
        $response->assertRedirect('/admin/zatca/devices/' . $device->id)->assertSessionHas('zatca_status');
        self::assertSame(DeviceLifecycleStatus::Active, $device->refresh()->lifecycle_status);

        // the OTP is neither flashed nor stored anywhere readable
        self::assertStringNotContainsString('804217', json_encode(session()->all()));
        self::assertStringNotContainsString('804217', json_encode(DeviceOnboarding::query()->get()->toArray()));
        self::assertStringNotContainsString('804217', json_encode(AuditLog::query()->get()->toArray()));
    }

    public function test_import_checks_then_registers_devices_from_an_uploaded_csv(): void
    {
        $store = Branch::query()->firstOrFail();
        $csv = "company_id,store_id,name,invoice_types,industry,location\n"
            . "{$store->company_id},{$store->id},POS-CSV-1,0100,Retail,Riyadh\n"
            . "{$store->company_id},{$store->id},POS-CSV-2,1000,Retail,Riyadh\n"
            . "{$store->company_id},99999,POS-CSV-3,0100,Retail,Riyadh\n";

        $this->login()->get('/admin/zatca/devices/import')->assertOk()->assertSee('Import devices from a file');

        $this->login()->post('/admin/zatca/devices/import', [
            'file' => UploadedFile::fake()->createWithContent('devices.csv', $csv), 'ids' => 'package', 'validate_only' => '1',
        ])->assertOk()->assertSee('Check result')->assertSee('valid: 2')->assertSee('failed: 1');
        self::assertSame(0, Device::query()->where('name', 'like', 'POS-CSV-%')->count());

        $this->login()->post('/admin/zatca/devices/import', [
            'file' => UploadedFile::fake()->createWithContent('devices.csv', $csv), 'ids' => 'package',
        ])->assertOk()->assertSee('Import result')->assertSee('created: 2')->assertSee('failed: 1');
        self::assertSame(2, Device::query()->where('name', 'like', 'POS-CSV-%')->count());

        $this->login()->post('/admin/zatca/devices/import', ['file' => UploadedFile::fake()->createWithContent('devices.exe', 'x')])
            ->assertRedirect()->assertSessionHas('zatca_error');
    }

    public function test_device_actions_need_the_onboard_ability(): void
    {
        $this->granted = [Abilities::DEVICES_VIEW];
        $this->login()->get('/admin/zatca/devices')->assertOk()->assertDontSee('Register device');
        $this->login()->get('/admin/zatca/devices/new')->assertForbidden();
        $this->login()->post('/admin/zatca/devices/' . $this->device->id . '/onboard', ['otp' => '123456'])->assertForbidden();
        $this->login()->post('/admin/zatca/devices/' . $this->device->id . '/renew', ['otp' => '123456'])->assertForbidden();
        $this->login()->post('/admin/zatca/devices/import', [])->assertForbidden();

        $this->granted = [];
        $this->login()->get('/admin/zatca/devices')->assertForbidden();
    }

    // ---------------------------------------------------------------- companies

    public function test_companies_and_stores_can_be_added(): void
    {
        $this->login()->get('/admin/zatca/companies')->assertOk()->assertSee('Maximum Speed')->assertSee('Riyadh Branch');

        $this->login()->post('/admin/zatca/companies', [
            'name' => 'Second Co', 'legal_name' => 'Second Company LLC', 'vat_number' => '300000000000003', 'external_ref' => 'HOST-7',
            'street' => 'King Fahd', 'building_number' => '1234', 'district' => 'Olaya', 'city' => 'Riyadh', 'postal_code' => '12345',
        ])->assertRedirect()->assertSessionHas('zatca_status');
        $company = Company::query()->where('external_ref', 'HOST-7')->firstOrFail();

        $this->login()->post('/admin/zatca/companies/' . $company->id . '/stores', ['code' => 'JED', 'name' => 'Jeddah'])
            ->assertSessionHas('zatca_status');
        self::assertTrue(Branch::query()->where('company_id', $company->id)->where('code', 'JED')->exists());

        $this->login()->post('/admin/zatca/companies', ['name' => 'No legal name'])->assertSessionHas('zatca_error');

        $this->granted = [Abilities::COMPANIES_VIEW];
        $this->login()->get('/admin/zatca/companies')->assertOk()->assertDontSee('Add a company');
        $this->login()->post('/admin/zatca/companies', ['name' => 'X'])->assertForbidden();
    }

    // ---------------------------------------------------------------- errors

    public function test_errors_are_listed_and_resolved_with_a_note_and_audited(): void
    {
        $invoice = app(ZatcaManager::class)->b2c()->issue(
            ['invoice_number' => 'ERR-1', 'lines' => [['name' => 'Tea', 'quantity' => '1', 'unit_price' => '5.00', 'vat_category' => 'S', 'vat_rate' => '15']]],
            ['device' => $this->device->id],
        );
        $model = Invoice::query()->where('public_id', $invoice->toArray()['invoice_id'])->firstOrFail();
        $recorder = app(ErrorRecorder::class);
        $first = $recorder->record(ErrorCategory::ApiConnection, Severity::Error, 'HTTP-503', 'Service unavailable', 'test', ['invoice' => $model]);
        $recorder->record(ErrorCategory::ApiConnection, Severity::Error, 'HTTP-503', 'Service unavailable', 'test', ['invoice' => $model]);
        self::assertSame(2, (int) $model->refresh()->unresolved_error_count);

        $this->login()->get('/admin/zatca/errors')->assertOk()->assertSee('HTTP-503')->assertSee('Service unavailable')->assertSee('ERR-1');
        $this->login()->get('/admin/zatca/errors?category=api_connection&severity=ERROR&workflow=b2c&code=HTTP-503')->assertOk()->assertSee('Service unavailable');
        $this->login()->get('/admin/zatca/errors/groups')->assertOk()->assertSee('HTTP-503');
        $this->login()->get('/admin/zatca/errors/warnings')->assertOk();

        $this->login()->post('/admin/zatca/errors/' . $first->id . '/resolve', ['note' => ' '])->assertSessionHas('zatca_error');
        self::assertFalse((bool) $first->refresh()->is_resolved);

        $this->login()->post('/admin/zatca/errors/' . $first->id . '/resolve', ['note' => 'ZATCA outage, retried later'])->assertSessionHas('zatca_status');
        $first->refresh();
        self::assertTrue($first->is_resolved);
        self::assertSame(5, (int) $first->resolved_by);
        self::assertSame(1, (int) $model->refresh()->unresolved_error_count);
        self::assertSame(1, (int) ErrorGroup::query()->findOrFail($first->group_id)->open_count);

        $this->login()->post('/admin/zatca/errors/groups/' . $first->group_id . '/resolve', ['note' => 'All handled'])->assertSessionHas('zatca_status');
        self::assertSame(0, ZatcaError::query()->where('is_resolved', false)->count());
        self::assertSame(0, (int) $model->refresh()->unresolved_error_count);
        self::assertSame(0, (int) ErrorGroup::query()->findOrFail($first->group_id)->open_count);

        self::assertSame(['error.resolved', 'error_group.resolved'], AuditLog::query()->whereIn('action', ['error.resolved', 'error_group.resolved'])->orderBy('id')->pluck('action')->all());
        self::assertSame(5, (int) AuditLog::query()->where('action', 'error_group.resolved')->value('actor_id'));
        self::assertTrue(app(\CodingTriplets\Zatca\Laravel\Audit\AuditLogger::class)->verifyChain()['valid']);

        $this->granted = [Abilities::ERRORS_VIEW];
        $this->login()->post('/admin/zatca/errors/' . $first->id . '/resolve', ['note' => 'x'])->assertForbidden();
    }

    // ---------------------------------------------------------------- operations

    public function test_queue_audit_and_settings_pages(): void
    {
        app(ZatcaManager::class)->b2c()->issue(
            ['invoice_number' => 'Q-1', 'lines' => [['name' => 'Tea', 'quantity' => '1', 'unit_price' => '5.00', 'vat_category' => 'S', 'vat_rate' => '15']]],
            ['device' => $this->device->id],
        );
        $this->login()->get('/admin/zatca/queue')->assertOk()->assertSee('Outbox')->assertSee('B2C reporting submissions');

        app(\CodingTriplets\Zatca\Laravel\Audit\AuditLogger::class)->record('test.entry', 'invoice', 1, ['field' => 'value'], 5);
        $this->login()->get('/admin/zatca/audit')->assertOk()->assertSee('test.entry');
        $this->login()->get('/admin/zatca/audit?action=test.&actor=5&subject=invoice')->assertOk()->assertSee('test.entry');
        $this->login()->get('/admin/zatca/audit?action=nothing')->assertOk()->assertDontSee('test.entry');
        $this->login()->post('/admin/zatca/audit/verify')->assertSessionHas('zatca_status');

        config(['zatca.security.encryption_key' => 'base64:SUPER-SECRET-KEY-VALUE']);
        $this->login()->get('/admin/zatca/settings')->assertOk()->assertSee('zatca.environment')
            ->assertDontSee('SUPER-SECRET-KEY-VALUE')->assertDontSee('encryption_key');

        $this->granted = [Abilities::DASHBOARD_VIEW];
        $this->login()->get('/admin/zatca/queue')->assertForbidden();
        $this->login()->get('/admin/zatca/audit')->assertForbidden();
        $this->login()->get('/admin/zatca/settings')->assertForbidden();
        $this->login()->post('/admin/zatca/audit/verify')->assertForbidden();
    }

    private function login(): self
    {
        return $this->actingAs(new GenericUser(['id' => 5, 'name' => 'Operator']));
    }
}
