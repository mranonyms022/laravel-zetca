<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Tests\Integration\Dashboard;

use CodingTriplets\Zatca\Core\Domain\Enums\DeviceLifecycleStatus as S;
use CodingTriplets\Zatca\Core\Fatoora\BasicAuthentication;
use CodingTriplets\Zatca\Laravel\Dashboard\Abilities;
use CodingTriplets\Zatca\Laravel\Database\Models\AuditLog;
use CodingTriplets\Zatca\Laravel\Database\Models\Branch;
use CodingTriplets\Zatca\Laravel\Database\Models\Company;
use CodingTriplets\Zatca\Laravel\Database\Models\Device;
use CodingTriplets\Zatca\Laravel\Database\Models\DeviceKey;
use CodingTriplets\Zatca\Laravel\Database\Models\VatRegistration;
use CodingTriplets\Zatca\Laravel\Devices\CompanyService;
use CodingTriplets\Zatca\Laravel\Devices\DeviceRegistrar;
use CodingTriplets\Zatca\Laravel\Fatoora\FatooraClientFactory;
use CodingTriplets\Zatca\Laravel\Invoices\DeviceUnavailable;
use CodingTriplets\Zatca\Laravel\ZatcaManager;
use CodingTriplets\Zatca\Tests\Integration\IntegrationTestCase;
use CodingTriplets\Zatca\Tests\Integration\Support\OnboardsDevices;
use CodingTriplets\Zatca\Tests\Support\FakeZatca;
use Illuminate\Auth\GenericUser;

/** Companies, stores and devices: edit, disable / enable, delete from the dashboard. */
final class DashboardOrganisationTest extends IntegrationTestCase
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

    public function test_pages_show_tables_with_actions(): void
    {
        $this->login()->get('/admin/zatca/companies')->assertOk()
            ->assertSee('Maximum Speed')->assertSee('399999999900003')->assertSee('Riyadh Branch')
            ->assertSee('/admin/zatca/companies/' . $this->device->company_id . '/edit', false)
            ->assertSee('/admin/zatca/stores/' . $this->device->branch_id . '/edit', false)
            ->assertSee('Disable')->assertSee('Delete');
        $this->login()->get('/admin/zatca/devices')->assertOk()
            ->assertSee('/admin/zatca/devices/' . $this->device->id . '/edit', false);
        $this->login()->get('/admin/zatca/companies/' . $this->device->company_id . '/edit')->assertOk()->assertSee('Maximum Speed Tech Supply LTD');
        $this->login()->get('/admin/zatca/stores/' . $this->device->branch_id . '/edit')->assertOk()->assertSee('onboarded device(s)');
        $this->login()->get('/admin/zatca/devices/' . $this->device->id . '/edit')->assertOk()->assertSee('can only be changed while');
    }

    // ---------------------------------------------------------------- companies

    public function test_company_is_edited_and_audited(): void
    {
        $id = (int) $this->device->company_id;
        $this->login()->put("/admin/zatca/companies/{$id}", [
            'name' => 'Max Speed', 'legal_name' => 'Max Speed LLC', 'crn' => '1010099999', 'external_ref' => 'HOST-1',
            'street' => 'Olaya', 'building_number' => '1111', 'district' => 'Al-Olaya', 'city' => 'Jeddah', 'postal_code' => '21111',
        ])->assertRedirect()->assertSessionHas('zatca_status');

        $company = Company::query()->findOrFail($id);
        self::assertSame(['Max Speed', '1010099999', 'HOST-1', 'Jeddah', 'SA'], [$company->name_en, $company->crn, $company->external_ref, $company->address['city'], $company->address['country']]);
        $registration = VatRegistration::query()->where('company_id', $id)->sole();
        self::assertSame(['Max Speed LLC', 'Jeddah'], [$registration->legal_name, $registration->address['city']]);
        self::assertTrue(AuditLog::query()->where('action', 'company.updated')->where('subject_id', $id)->exists());

        // external_ref must stay unique
        app(CompanyService::class)->create(['name' => 'Other', 'legal_name' => 'Other LLC', 'vat_number' => '300000000000003', 'external_ref' => 'HOST-2']);
        $this->login()->put("/admin/zatca/companies/{$id}", ['name' => 'Max Speed', 'legal_name' => 'Max Speed LLC', 'external_ref' => 'HOST-2'])
            ->assertSessionHas('zatca_error');
        self::assertSame('HOST-1', Company::query()->findOrFail($id)->external_ref);
    }

    public function test_disabled_company_or_store_refuses_new_invoices(): void
    {
        $company = (int) $this->device->company_id;
        $this->login()->post("/admin/zatca/companies/{$company}/disable")->assertSessionHas('zatca_status');
        self::assertSame('disabled', Company::query()->findOrFail($company)->status);
        $this->login()->get('/admin/zatca/companies')->assertSee('Enable');
        $this->assertIssueRefused('its company is disabled');

        $this->login()->post("/admin/zatca/companies/{$company}/enable");
        $store = (int) $this->device->branch_id;
        $this->login()->post("/admin/zatca/stores/{$store}/disable")->assertSessionHas('zatca_status');
        $this->assertIssueRefused('its store is disabled');

        $this->login()->post("/admin/zatca/stores/{$store}/enable");
        $this->zatca->reported();
        self::assertSame(1, app(ZatcaManager::class)->b2c()->issue($this->payload('ORG-OK'), ['device' => $this->device->id])->toArray()['counter']);
    }

    public function test_company_with_devices_is_not_deleted_but_an_empty_one_is_and_can_come_back(): void
    {
        $this->login()->delete('/admin/zatca/companies/' . $this->device->company_id)->assertSessionHas('zatca_error');
        self::assertNotNull(Company::query()->find($this->device->company_id));

        $empty = app(CompanyService::class)->create(['name' => 'Empty', 'legal_name' => 'Empty LLC', 'vat_number' => '300000000000003',
            'stores' => [['code' => 'S1', 'name' => 'Store 1']]]);
        $this->login()->delete("/admin/zatca/companies/{$empty->id}")->assertSessionHas('zatca_status');
        self::assertNull(Company::query()->find($empty->id));
        self::assertSame(0, Branch::query()->where('company_id', $empty->id)->count());

        // adding the same VAT number again restores the company instead of failing on the unique registration
        $again = app(CompanyService::class)->create(['name' => 'Empty', 'legal_name' => 'Empty LLC', 'vat_number' => '300000000000003']);
        self::assertSame((int) $empty->id, (int) $again->id);
        self::assertSame('active', $again->status);
    }

    // ---------------------------------------------------------------- stores

    public function test_store_is_added_edited_and_deleted(): void
    {
        $company = (int) $this->device->company_id;
        $this->login()->post('/admin/zatca/stores', ['company_id' => $company, 'code' => 'JED', 'name' => 'Jeddah'])->assertSessionHas('zatca_status');
        $store = Branch::query()->where('company_id', $company)->where('code', 'JED')->firstOrFail();

        $this->login()->put("/admin/zatca/stores/{$store->id}", ['code' => 'JED1', 'name' => 'Jeddah Corniche', 'external_ref' => 'S-9'])->assertSessionHas('zatca_status');
        self::assertSame(['JED1', 'Jeddah Corniche', 'S-9'], [$store->refresh()->code, $store->name, $store->external_ref]);

        // codes stay unique within the company
        $this->login()->put("/admin/zatca/stores/{$store->id}", ['code' => 'RYD', 'name' => 'Clash'])->assertSessionHas('zatca_error');

        // a store with devices is kept
        $this->login()->delete('/admin/zatca/stores/' . $this->device->branch_id)->assertSessionHas('zatca_error');

        $this->login()->delete("/admin/zatca/stores/{$store->id}")->assertSessionHas('zatca_status');
        self::assertNull(Branch::query()->find($store->id));
        $restored = app(CompanyService::class)->createStore($company, ['code' => 'JED1', 'name' => 'Jeddah again']);
        self::assertSame((int) $store->id, (int) $restored->id);
        self::assertSame('Jeddah again', $restored->name);
    }

    // ---------------------------------------------------------------- devices

    public function test_device_is_disabled_and_enabled(): void
    {
        $this->login()->post('/admin/zatca/devices/' . $this->device->id . '/disable')->assertSessionHas('zatca_status');
        self::assertSame(S::Suspended, $this->device->refresh()->lifecycle_status);
        // the invoice is recorded but not issued: no ICV, no QR
        $result = app(ZatcaManager::class)->b2c()->issue($this->payload('ORG-SUSP'), ['device' => $this->device->id])->toArray();
        self::assertFalse($result['status']);
        self::assertNull($result['counter'] ?? null);

        $this->login()->post('/admin/zatca/devices/' . $this->device->id . '/enable')->assertSessionHas('zatca_status');
        self::assertSame(S::Active, $this->device->refresh()->lifecycle_status);
        $this->login()->post('/admin/zatca/devices/' . $this->device->id . '/enable')->assertSessionHas('zatca_error');
    }

    public function test_onboarded_device_is_not_edited_and_is_decommissioned_instead_of_deleted(): void
    {
        $this->login()->put('/admin/zatca/devices/' . $this->device->id, ['name' => 'Renamed', 'industry' => 'x', 'location' => 'y'])
            ->assertSessionHas('zatca_error');
        self::assertSame('POS-RYD-01', $this->device->refresh()->name);

        $this->login()->delete('/admin/zatca/devices/' . $this->device->id)->assertSessionHas('zatca_status');
        $device = Device::query()->findOrFail($this->device->id);
        self::assertSame(S::Decommissioned, $device->lifecycle_status);
        self::assertNotNull($device->decommissioned_at);
        $this->login()->get('/admin/zatca/devices')->assertOk()->assertSee('DECOMMISSIONED');
    }

    public function test_registered_device_is_edited_and_deleted(): void
    {
        $registration = VatRegistration::query()->where('company_id', $this->device->company_id)->sole();
        $store = app(CompanyService::class)->createStore((int) $this->device->company_id, ['code' => 'DMM', 'name' => 'Dammam']);
        $new = app(DeviceRegistrar::class)->registerDevice([
            'vat_registration_id' => $registration->id, 'branch_id' => $this->device->branch_id, 'name' => 'POS-NEW',
            'invoice_type_flags' => '0100', 'environment' => 'sandbox', 'industry' => 'Retail', 'location' => 'Riyadh',
        ]);

        $this->login()->get("/admin/zatca/devices/{$new->id}/edit")->assertOk()->assertSee('Save changes');
        $this->login()->put("/admin/zatca/devices/{$new->id}", ['name' => 'POS-DMM-01', 'store_id' => $store->id, 'industry' => 'Food', 'location' => 'Dammam'])
            ->assertSessionHas('zatca_status');
        $new->refresh();
        self::assertSame(['POS-DMM-01', (int) $store->id, 'Food', 'Dammam'], [$new->name, (int) $new->branch_id, $new->industry, $new->location]);

        // the store must belong to the device's company
        $other = app(CompanyService::class)->create(['name' => 'Other', 'legal_name' => 'Other LLC', 'vat_number' => '300000000000003', 'stores' => [['code' => 'X', 'name' => 'X']]]);
        $foreign = Branch::query()->where('company_id', $other->id)->sole();
        $this->login()->put("/admin/zatca/devices/{$new->id}", ['store_id' => $foreign->id])->assertSessionHas('zatca_error');

        $this->login()->delete("/admin/zatca/devices/{$new->id}")->assertSessionHas('zatca_status');
        self::assertNull(Device::query()->find($new->id));
        self::assertSame(0, DeviceKey::query()->where('device_id', $new->id)->count());
        self::assertTrue(AuditLog::query()->where('action', 'device.deleted')->where('subject_id', $new->id)->exists());
    }

    // ---------------------------------------------------------------- permissions

    public function test_actions_need_the_manage_abilities(): void
    {
        $this->granted = [Abilities::COMPANIES_VIEW, Abilities::DEVICES_VIEW];
        $company = (int) $this->device->company_id;
        $store = (int) $this->device->branch_id;
        $device = (int) $this->device->id;

        $this->login()->get('/admin/zatca/companies')->assertOk()->assertDontSee("/admin/zatca/companies/{$company}/edit", false);
        $this->login()->get('/admin/zatca/devices')->assertOk()->assertDontSee("/admin/zatca/devices/{$device}/edit", false);

        $this->login()->get("/admin/zatca/companies/{$company}/edit")->assertForbidden();
        $this->login()->put("/admin/zatca/companies/{$company}", ['name' => 'X'])->assertForbidden();
        $this->login()->post("/admin/zatca/companies/{$company}/disable")->assertForbidden();
        $this->login()->delete("/admin/zatca/companies/{$company}")->assertForbidden();
        $this->login()->post('/admin/zatca/stores', ['company_id' => $company, 'code' => 'A', 'name' => 'A'])->assertForbidden();
        $this->login()->put("/admin/zatca/stores/{$store}", ['code' => 'A', 'name' => 'A'])->assertForbidden();
        $this->login()->post("/admin/zatca/stores/{$store}/disable")->assertForbidden();
        $this->login()->delete("/admin/zatca/stores/{$store}")->assertForbidden();
        $this->login()->get("/admin/zatca/devices/{$device}/edit")->assertForbidden();
        $this->login()->post("/admin/zatca/devices/{$device}/disable")->assertForbidden();
        $this->login()->delete("/admin/zatca/devices/{$device}")->assertForbidden();

        self::assertSame('active', Company::query()->findOrFail($company)->status);
        self::assertSame(S::Active, $this->device->refresh()->lifecycle_status);
    }

    private function assertIssueRefused(string $reason): void
    {
        try {
            app(ZatcaManager::class)->b2c()->issue($this->payload('ORG-' . bin2hex(random_bytes(3))), ['device' => $this->device->id]);
            self::fail('Issuance should have been refused.');
        } catch (DeviceUnavailable $e) {
            self::assertStringContainsString($reason, $e->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function payload(string $number): array
    {
        return ['invoice_number' => $number, 'lines' => [['name' => 'Coffee', 'quantity' => '1', 'unit_price' => '10.00', 'vat_category' => 'S', 'vat_rate' => '15']]];
    }

    private function login(): self
    {
        return $this->actingAs(new GenericUser(['id' => 5, 'name' => 'Operator']));
    }
}
