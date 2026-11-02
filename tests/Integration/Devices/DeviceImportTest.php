<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Tests\Integration\Devices;

use CodingTriplets\Zatca\Core\Domain\Enums\DeviceLifecycleStatus;
use CodingTriplets\Zatca\Core\Domain\Exceptions\InvalidValue;
use CodingTriplets\Zatca\Core\Fatoora\BasicAuthentication;
use CodingTriplets\Zatca\Laravel\Database\Models\Branch;
use CodingTriplets\Zatca\Laravel\Database\Models\Company;
use CodingTriplets\Zatca\Laravel\Database\Models\Device;
use CodingTriplets\Zatca\Laravel\Database\Models\DeviceOnboarding;
use CodingTriplets\Zatca\Laravel\Fatoora\FatooraClientFactory;
use CodingTriplets\Zatca\Laravel\ZatcaManager;
use CodingTriplets\Zatca\Tests\Integration\IntegrationTestCase;
use CodingTriplets\Zatca\Tests\Support\FakeZatca;
use Illuminate\Support\Facades\Storage;

/** M6.1: companies / stores from code, devices from arrays or CSV/Excel files, bulk onboarding. */
final class DeviceImportTest extends IntegrationTestCase
{
    private FakeZatca $zatca;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        config(['zatca.devices.sandbox_skip_compliance_checks' => true]);
        $this->zatca = new FakeZatca();
        $this->app->instance(FatooraClientFactory::class, new FatooraClientFactory(new BasicAuthentication(), $this->zatca->stack));
        $this->dir = sys_get_temp_dir() . '/zatca-import-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_companies_and_stores_are_created_from_code_with_host_ids_and_idempotently(): void
    {
        $company = $this->company();
        self::assertSame('C-100', $company->external_ref);
        self::assertSame(['R-1', 'R-2'], Branch::query()->where('company_id', $company->id)->orderBy('id')->pluck('external_ref')->all());

        $again = $this->company();
        self::assertSame($company->id, $again->id);
        self::assertSame(1, Company::query()->count());
        self::assertSame(2, Branch::query()->count());

        $store = $this->zatca()->companies()->createStore('C-100', ['code' => 'JED', 'name' => 'Jeddah', 'external_ref' => 'R-3']);
        self::assertSame($store->id, $this->zatca()->companies()->resolveStore($company, 'R-3')->id);
        self::assertSame($store->id, $this->zatca()->companies()->resolveStore($company, 'JED')->id);
        self::assertSame($store->id, $this->zatca()->companies()->resolveStore($company, (string) $store->id, 'package')->id);
    }

    public function test_csv_import_registers_devices_by_host_ids_and_reports_each_row(): void
    {
        $company = $this->company();
        $csv = $this->csv([
            ['company_id', 'Store ID', 'Device Name', 'Invoice Types', 'Industry', 'Location'],
            ['C-100', 'R-1', 'POS-01', '1100', 'Retail', 'Riyadh'],
            ['C-100', 'R-2', 'POS-02', '100', 'Retail', 'Riyadh'],          // Excel-style "0100" → 100
            ['C-100', 'NOPE', 'POS-03', '1100', 'Retail', 'Riyadh'],         // unknown store
            ['C-100', 'R-1', 'POS-04', '1100', '', 'Riyadh'],                // missing industry
            ['C-100', 'R-1', 'POS-01', '1100', 'Retail', 'Riyadh'],          // duplicate row
        ]);

        $report = $this->zatca()->devices()->import($csv);

        self::assertSame(['created' => 2, 'failed' => 3], $report->counts());
        self::assertStringContainsString('store [NOPE] was not found', $report->rows()[2]['errors'][0]);
        self::assertStringContainsString('industry', $report->rows()[3]['errors'][0]);
        self::assertStringContainsString('duplicate of row 1', $report->rows()[4]['errors'][0]);
        self::assertSame('0100', Device::query()->where('name', 'POS-02')->value('invoice_type_flags'));
        self::assertSame((int) $company->id, (int) Device::query()->where('name', 'POS-01')->value('company_id'));

        $second = $this->zatca()->devices()->import($csv);
        self::assertSame(2, $second->count('existing'), 're-importing the same file creates nothing new');
        self::assertSame(2, Device::query()->count());
    }

    public function test_package_ids_work_too(): void
    {
        $company = $this->company();
        $store = Branch::query()->where('external_ref', 'R-1')->sole();

        $report = $this->zatca()->devices()->registerMany([
            ['company_id' => $company->id, 'store_id' => $store->id, 'name' => 'POS-P', 'industry' => 'Retail', 'location' => 'Riyadh'],
        ], ['ids' => 'package']);

        self::assertSame(['created' => 1], $report->counts());
        self::assertSame((int) $store->id, (int) Device::query()->sole()->branch_id);
    }

    public function test_otp_column_onboards_immediately_and_is_never_stored_or_reported(): void
    {
        $this->company();
        $this->zatca->csidForSubmittedCsr();
        $this->zatca->error(400, ['errorCode' => 'Invalid-OTP', 'errorMessage' => 'OTP is invalid']);
        $csv = $this->csv([
            ['company_id', 'store_id', 'name', 'invoice_types', 'industry', 'location', 'otp'],
            ['C-100', 'R-1', 'POS-OK', '1100', 'Retail', 'Riyadh', '804217'],
            ['C-100', 'R-1', 'POS-BADOTP', '1100', 'Retail', 'Riyadh', '650391'],
            ['C-100', 'R-1', 'POS-SHORT', '1100', 'Retail', 'Riyadh', '12'],
            ['C-100', 'R-1', 'POS-LATER', '1100', 'Retail', 'Riyadh', ''],
        ]);

        $report = $this->zatca()->devices()->import($csv);

        self::assertSame(['onboarded', 'onboarding_failed', 'onboarding_failed', 'created'], array_column($report->rows(), 'status'));
        self::assertSame(DeviceLifecycleStatus::Active, Device::query()->where('name', 'POS-OK')->sole()->lifecycle_status);
        self::assertSame(DeviceLifecycleStatus::Registered, Device::query()->where('name', 'POS-LATER')->sole()->lifecycle_status);
        $everything = json_encode($report->toArray()) . json_encode(DeviceOnboarding::query()->get()->toArray());
        self::assertStringNotContainsString('804217', $everything);
        self::assertStringNotContainsString('650391', $everything);

        // later: onboard the remaining device by itself
        $this->zatca->csidForSubmittedCsr();
        $later = $this->zatca()->devices()->onboardMany([['device' => 'POS-LATER', 'otp' => '111111']]);
        self::assertSame('onboarded', $later->rows()[0]['status']);
    }

    public function test_validate_only_writes_nothing(): void
    {
        $this->company();
        $report = $this->zatca()->devices()->validateMany([
            ['company_id' => 'C-100', 'store_id' => 'R-1', 'name' => 'POS-V', 'industry' => 'Retail', 'location' => 'Riyadh'],
            ['company_id' => 'C-404', 'store_id' => 'R-1', 'name' => 'POS-X', 'industry' => 'Retail', 'location' => 'Riyadh'],
            ['company_id' => 'C-100', 'store_id' => 'R-1', 'name' => 'POS-Y', 'invoice_types' => '9999', 'industry' => 'Retail', 'location' => 'Riyadh'],
        ]);

        self::assertSame(['valid', 'failed', 'failed'], array_column($report->rows(), 'status'));
        self::assertSame(0, Device::query()->count());
    }

    public function test_xlsx_files_and_files_on_a_filesystem_disk_are_read(): void
    {
        if (! class_exists(\OpenSpout\Writer\XLSX\Writer::class)) {
            self::markTestSkipped('openspout/openspout not installed.');
        }
        $this->company();
        $path = $this->dir . '/devices.xlsx';
        $writer = new \OpenSpout\Writer\XLSX\Writer();
        $writer->openToFile($path);
        foreach ([['company_id', 'store_id', 'name', 'invoice_types', 'industry', 'location'], ['C-100', 'R-2', 'POS-X1', 100, 'Retail', 'Riyadh']] as $cells) {
            $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues($cells));
        }
        $writer->close();

        self::assertSame(['created' => 1], $this->zatca()->devices()->import($path)->counts());
        self::assertSame('0100', Device::query()->sole()->invoice_type_flags);

        Storage::fake('imports');
        Storage::disk('imports')->put('up/devices.csv', "company_id;store_id;name;industry;location\nC-100;R-1;POS-D1;Retail;Riyadh\n");
        self::assertSame(['created' => 1], $this->zatca()->devices()->import(['disk' => 'imports', 'path' => 'up/devices.csv'])->counts());
    }

    public function test_unsupported_or_missing_files_are_refused(): void
    {
        $this->expectException(InvalidValue::class);
        $this->zatca()->devices()->import($this->dir . '/missing.csv');
    }

    public function test_import_command(): void
    {
        $this->company();
        $csv = $this->csv([['company_id', 'store_id', 'name', 'industry', 'location'], ['C-100', 'R-1', 'POS-CMD', 'Retail', 'Riyadh']]);

        $this->artisan('zatca:devices:import', ['file' => $csv, '--validate' => true])->assertExitCode(0);
        self::assertSame(0, Device::query()->count());
        $this->artisan('zatca:devices:import', ['file' => $csv])->expectsOutputToContain('created: 1')->assertExitCode(0);
        self::assertSame(1, Device::query()->count());
    }

    private function company(): Company
    {
        return $this->zatca()->companies()->create([
            'external_ref' => 'C-100',
            'name' => 'Maximum Speed',
            'legal_name' => 'Maximum Speed Tech Supply LTD',
            'vat_number' => '399999999900003',
            'crn' => '1010010000',
            'address' => ['street' => 'Prince Sultan', 'building_number' => '2322', 'district' => 'Al-Murabba', 'city' => 'Riyadh', 'postal_code' => '23333'],
            'stores' => [
                ['code' => 'RYD', 'name' => 'Riyadh Branch', 'external_ref' => 'R-1'],
                ['code' => 'DMM', 'name' => 'Dammam Branch', 'external_ref' => 'R-2'],
            ],
        ]);
    }

    /** @param list<list<string>> $rows */
    private function csv(array $rows): string
    {
        $path = $this->dir . '/devices-' . bin2hex(random_bytes(3)) . '.csv';
        $h = fopen($path, 'wb');
        fwrite($h, "\xEF\xBB\xBF");   // Excel's UTF-8 BOM
        foreach ($rows as $row) {
            fputcsv($h, $row, ',', '"', '\\');
        }
        fclose($h);

        return $path;
    }

    private function zatca(): ZatcaManager
    {
        return app(ZatcaManager::class);
    }
}
