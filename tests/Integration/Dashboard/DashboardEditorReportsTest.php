<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Tests\Integration\Dashboard;

use CodingTriplets\Zatca\Core\Fatoora\BasicAuthentication;
use CodingTriplets\Zatca\Laravel\Dashboard\Abilities;
use CodingTriplets\Zatca\Laravel\Dashboard\Http\Controllers\ReportController;
use CodingTriplets\Zatca\Laravel\Database\Models\AuditLog;
use CodingTriplets\Zatca\Laravel\Database\Models\Device;
use CodingTriplets\Zatca\Laravel\Database\Models\Invoice;
use CodingTriplets\Zatca\Laravel\Database\Models\InvoiceIssuance;
use CodingTriplets\Zatca\Laravel\Database\Models\InvoiceVersion;
use CodingTriplets\Zatca\Laravel\Fatoora\FatooraClientFactory;
use CodingTriplets\Zatca\Laravel\Processing\Outbox\OutboxRelay;
use CodingTriplets\Zatca\Laravel\ZatcaManager;
use CodingTriplets\Zatca\Tests\Integration\IntegrationTestCase;
use CodingTriplets\Zatca\Tests\Integration\Support\OnboardsDevices;
use CodingTriplets\Zatca\Tests\Support\FakeZatca;
use Illuminate\Auth\GenericUser;

/** M7c: correction editor, credit/debit note forms, reports with charts, CSV exports. */
final class DashboardEditorReportsTest extends IntegrationTestCase
{
    use OnboardsDevices;

    private FakeZatca $zatca;

    private Device $device;

    private string $storage;

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
        $this->storage = sys_get_temp_dir() . '/zatca-ui-' . bin2hex(random_bytes(4));
        config([
            'filesystems.disks.zatca_test' => ['driver' => 'local', 'root' => $this->storage],
            'zatca.storage.disk' => 'zatca_test',
            'zatca.devices.sandbox_skip_compliance_checks' => true,
        ]);
        $this->zatca = new FakeZatca();
        $this->app->instance(FatooraClientFactory::class, new FatooraClientFactory(new BasicAuthentication(), $this->zatca->stack));
        $this->device = $this->activeDevice();
        app(ZatcaManager::class)->auth(fn ($user, string $ability) => $this->granted === null || in_array($ability, $this->granted, true));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storage)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->storage, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->storage);
        }
        parent::tearDown();
    }

    // ---------------------------------------------------------------- correction editor

    public function test_correction_editor_checks_then_saves_a_new_version_and_document(): void
    {
        $invoice = $this->rejected('ED-1');
        $url = '/admin/zatca/invoice/' . $invoice->public_id . '/correct';

        $this->login()->get('/admin/zatca/invoice/' . $invoice->public_id)->assertOk()->assertSee('Correct JSON')->assertDontSee('Credit note');
        $this->login()->get($url)->assertOk()->assertSee('Corrected JSON')->assertSee('BR-KSA-37')->assertSee('Coffee');

        // broken JSON: nothing saved
        $this->login()->post($url, ['payload' => '{"lines": [', 'reason' => 'x', 'intent' => 'save'])
            ->assertSessionHas('zatca_error');
        // invalid invoice: check reports the errors, nothing saved
        $invalid = json_encode(['invoice_number' => 'ED-1', 'lines' => []]);
        $this->login()->post($url, ['payload' => $invalid, 'intent' => 'validate'])
            ->assertSessionHas('zatca_check', fn ($c) => $c['valid'] === false && $c['errors'] !== []);
        $this->login()->post($url, ['payload' => $invalid, 'reason' => 'try', 'intent' => 'save'])
            ->assertSessionHas('zatca_check', fn ($c) => $c['valid'] === false);
        self::assertSame(1, InvoiceVersion::query()->where('invoice_id', $invoice->id)->count());

        $fixed = json_encode($this->payload('ED-1', '3'));
        $this->login()->post($url, ['payload' => $fixed, 'intent' => 'validate'])
            ->assertSessionHas('zatca_check', fn ($c) => $c['valid'] === true);
        self::assertSame(1, InvoiceVersion::query()->where('invoice_id', $invoice->id)->count());

        // reason required
        $this->login()->post($url, ['payload' => $fixed, 'reason' => '', 'intent' => 'save'])->assertSessionHas('zatca_error');

        $this->login()->post($url, ['payload' => $fixed, 'reason' => 'Quantity corrected', 'intent' => 'save'])
            ->assertRedirect('/admin/zatca/invoice/' . $invoice->public_id . '?tab=versions')->assertSessionHas('zatca_status');
        $versions = InvoiceVersion::query()->where('invoice_id', $invoice->id)->orderBy('version')->get();
        self::assertCount(2, $versions);
        self::assertSame('Quantity corrected', $versions[1]->reason);
        self::assertSame(5, (int) $versions[1]->created_by);
        self::assertSame('2', (string) $versions[0]->payload['lines'][0]['quantity']);   // the original is untouched
        self::assertSame(2, InvoiceIssuance::query()->where('invoice_id', $invoice->id)->count());
        self::assertTrue(AuditLog::query()->where('action', 'invoice.correct')->where('actor_id', 5)->exists());

        // no editor once the invoice is waiting for ZATCA again
        $this->login()->get($url)->assertNotFound();
    }

    public function test_editor_and_notes_need_the_correct_ability(): void
    {
        $rejected = $this->rejected('ED-2');
        $reported = $this->reported('ED-3');
        $this->granted = [Abilities::DASHBOARD_VIEW, Abilities::B2C_VIEW];

        $this->login()->get('/admin/zatca/invoice/' . $rejected->public_id)->assertOk()->assertDontSee('Correct JSON');
        $this->login()->get('/admin/zatca/invoice/' . $rejected->public_id . '/correct')->assertForbidden();
        $this->login()->post('/admin/zatca/invoice/' . $rejected->public_id . '/correct', ['payload' => '{}'])->assertForbidden();
        $this->login()->get('/admin/zatca/invoice/' . $reported->public_id . '/note/credit')->assertForbidden();
        $this->login()->post('/admin/zatca/invoice/' . $reported->public_id . '/note/debit', ['lines' => '[]'])->assertForbidden();
    }

    // ---------------------------------------------------------------- notes

    public function test_credit_and_debit_note_forms(): void
    {
        $invoice = $this->reported('NT-1');   // 2 × 10.00 + 15 % = 23.00
        $base = '/admin/zatca/invoice/' . $invoice->public_id;

        $this->login()->get($base)->assertOk()->assertSee('Credit note')->assertSee('Debit note')->assertDontSee('Correct JSON');
        $this->login()->get($base . '/note/credit')->assertOk()->assertSee('23.00')->assertSee('NT-1-CN1')->assertSee('Coffee');
        $this->login()->get($base . '/note/other')->assertNotFound();

        $line = static fn (string $qty) => json_encode([['name' => 'Coffee (returned)', 'quantity' => $qty, 'unit_price' => '10.00', 'vat_category' => 'S', 'vat_rate' => '15']]);

        $this->login()->post($base . '/note/credit', ['invoice_number' => 'NT-1-CN1', 'reason' => 'Returned', 'lines' => '{bad'])->assertSessionHas('zatca_error');
        $this->login()->post($base . '/note/credit', ['invoice_number' => 'NT-1-CN1', 'reason' => 'Returned', 'lines' => '[]'])->assertSessionHas('zatca_error');
        // more than the invoice: refused before anything is signed
        $this->login()->post($base . '/note/credit', ['invoice_number' => 'NT-1-CN1', 'reason' => 'Returned', 'lines' => $line('3')])
            ->assertSessionHas('zatca_error', fn ($m) => str_contains($m, 'exceeds'));
        self::assertSame(0, Invoice::query()->where('billing_reference_invoice_id', $invoice->id)->count());

        $this->login()->post($base . '/note/credit', ['invoice_number' => 'NT-1-CN1', 'reason' => 'Returned', 'lines' => $line('1')])
            ->assertRedirect()->assertSessionHas('zatca_status');
        $note = Invoice::query()->where('invoice_number', 'NT-1-CN1')->firstOrFail();
        self::assertSame('381', $note->document_type->value);
        self::assertSame($invoice->id, (int) $note->billing_reference_invoice_id);
        self::assertSame('11.50', number_format((float) $invoice->refresh()->credited_amount, 2));

        $this->login()->post($base . '/note/debit', ['invoice_number' => 'NT-1-DN1', 'reason' => 'Delivery', 'lines' => json_encode([['name' => 'Delivery', 'quantity' => '1', 'unit_price' => '20.00', 'vat_category' => 'S', 'vat_rate' => '15']])])
            ->assertSessionHas('zatca_status');
        self::assertSame('383', Invoice::query()->where('invoice_number', 'NT-1-DN1')->firstOrFail()->document_type->value);
        self::assertSame(2, AuditLog::query()->whereIn('action', ['invoice.credit_note', 'invoice.debit_note'])->count());
    }

    // ---------------------------------------------------------------- reports & exports

    public function test_reports_page_charts_and_vat_summary(): void
    {
        $this->reported('RP-1');
        $this->reported('RP-2');
        $this->rejected('RP-3');

        $response = $this->login()->get('/admin/zatca/reports')->assertOk()
            ->assertSee('B2C · documents per day')->assertSee('<svg', false)->assertSee('VAT summary')
            ->assertSee('POS-RYD-01')->assertSee('zatca rejection');
        // 2 reported invoices × 23.00 → net incl. VAT 46.00, VAT 6.00
        $response->assertSee('46.00')->assertSee('6.00');

        $this->login()->get('/admin/zatca/reports?from=2000-01-01&to=2000-01-31&workflow=b2c')->assertOk()->assertSee('No documents in this period.');
        $this->login()->get('/admin/zatca?period=all')->assertOk()->assertSee('Last 14 days');

        $csv = $this->login()->get('/admin/zatca/reports/vat.csv')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->streamedContent();
        self::assertStringContainsString('b2c,invoice,2,40.00,6.00,46.00', $csv);
        self::assertStringContainsString('b2c,net,,40.00,6.00,46.00', $csv);
    }

    public function test_invoice_exports_respect_filters_and_permissions(): void
    {
        $this->reported('EX-1');
        $this->rejected('EX-2');

        $csv = $this->login()->get('/admin/zatca/reports/invoices.csv')->assertOk()->streamedContent();
        self::assertStringStartsWith("\xEF\xBB\xBFinvoice_id,workflow,document_type,invoice_number", $csv);
        self::assertStringContainsString(',EX-1,', $csv);
        self::assertStringContainsString(',EX-2,', $csv);

        $list = $this->login()->get('/admin/zatca/invoices/b2c/export.csv?tab=rejected')->assertOk()->streamedContent();
        self::assertStringContainsString(',EX-2,', $list);
        self::assertStringNotContainsString(',EX-1,', $list);
        $this->login()->get('/admin/zatca/invoices/b2c?tab=rejected')->assertSee('Export this list (CSV)');

        self::assertSame("'=HYPERLINK(\"x\")", ReportController::cell('=HYPERLINK("x")'));
        self::assertSame("'@SUM(A1)", ReportController::cell('@SUM(A1)'));
        self::assertSame('-12.50', ReportController::cell('-12.50'));

        $this->granted = [Abilities::DASHBOARD_VIEW, Abilities::B2C_VIEW, Abilities::REPORTS_VIEW];
        $this->login()->get('/admin/zatca/reports')->assertOk()->assertDontSee('Download all documents');
        $this->login()->get('/admin/zatca/reports/vat.csv')->assertOk();
        $this->login()->get('/admin/zatca/reports/invoices.csv')->assertForbidden();
        $this->login()->get('/admin/zatca/invoices/b2c/export.csv')->assertForbidden();

        $this->granted = [Abilities::DASHBOARD_VIEW];
        $this->login()->get('/admin/zatca/reports')->assertForbidden();
    }

    // ---------------------------------------------------------------- helpers

    private function login(): self
    {
        return $this->actingAs(new GenericUser(['id' => 5, 'name' => 'Operator']));
    }

    /** @return array<string, mixed> */
    private function payload(string $number, string $qty = '2'): array
    {
        return ['invoice_number' => $number, 'lines' => [['name' => 'Coffee', 'quantity' => $qty, 'unit_price' => '10.00', 'vat_category' => 'S', 'vat_rate' => '15']]];
    }

    private function issue(string $number): Invoice
    {
        $id = app(ZatcaManager::class)->b2c()->issue($this->payload($number), ['device' => $this->device->id])->toArray()['invoice_id'];

        return Invoice::query()->where('public_id', $id)->firstOrFail();
    }

    private function reported(string $number): Invoice
    {
        $invoice = $this->issue($number);
        $this->zatca->reported();
        app(OutboxRelay::class)->relay();

        return $invoice->refresh();
    }

    private function rejected(string $number): Invoice
    {
        $invoice = $this->issue($number);
        $this->zatca->notReported();
        app(OutboxRelay::class)->relay();

        return $invoice->refresh();
    }
}
