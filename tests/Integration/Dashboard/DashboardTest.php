<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Tests\Integration\Dashboard;

use CodingTriplets\Zatca\Core\Domain\Enums\ProcessingStatus;
use CodingTriplets\Zatca\Core\Fatoora\BasicAuthentication;
use CodingTriplets\Zatca\Laravel\Dashboard\Abilities;
use CodingTriplets\Zatca\Laravel\Database\Models\Device;
use CodingTriplets\Zatca\Laravel\Database\Models\Invoice;
use CodingTriplets\Zatca\Laravel\Database\Models\InvoiceArtifact;
use CodingTriplets\Zatca\Laravel\Fatoora\FatooraClientFactory;
use CodingTriplets\Zatca\Laravel\Processing\Outbox\OutboxRelay;
use CodingTriplets\Zatca\Laravel\ZatcaManager;
use CodingTriplets\Zatca\Tests\Integration\IntegrationTestCase;
use CodingTriplets\Zatca\Tests\Integration\Support\OnboardsDevices;
use CodingTriplets\Zatca\Tests\Support\FakeZatca;
use Illuminate\Auth\GenericUser;

/** M7a: dashboard home, B2B/B2C lists, invoice detail, operator actions, permissions. */
final class DashboardTest extends IntegrationTestCase
{
    use OnboardsDevices;

    private FakeZatca $zatca;

    private Device $device;

    private string $storage;

    /** @var list<string>|null abilities granted to the test user (null = all) */
    private ?array $granted = null;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        // No login page in the test app: authentication is simulated with actingAs().
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

    // ---------------------------------------------------------------- access

    public function test_guests_and_users_without_the_ability_are_refused(): void
    {
        $this->get('/admin/zatca')->assertForbidden();

        $this->granted = [];
        $this->login()->get('/admin/zatca')->assertForbidden();

        $this->granted = [Abilities::DASHBOARD_VIEW, Abilities::B2C_VIEW];
        $this->login()->get('/admin/zatca')->assertOk()->assertSee('B2C · Reporting')->assertDontSee('B2B · Clearance');
        $this->login()->get('/admin/zatca/invoices/b2b')->assertForbidden();
        $this->login()->get('/admin/zatca/invoices/b2c')->assertOk();
    }

    public function test_without_a_callback_only_the_local_environment_is_open(): void
    {
        // fresh manager without a Zatca::auth() callback
        $this->app->forgetInstance(ZatcaManager::class);
        $this->app->forgetInstance(\CodingTriplets\Zatca\Laravel\Dashboard\PermissionResolver::class);

        $this->app['env'] = 'production';
        $this->login()->get('/admin/zatca')->assertForbidden();   // GenericUser cannot use Gates

        $this->app['env'] = 'local';
        $this->login()->get('/admin/zatca')->assertOk();
    }

    // ---------------------------------------------------------------- pages

    public function test_home_shows_counts_per_workflow(): void
    {
        $this->issue('UI-1');
        $this->issue('UI-2');
        $this->zatca->reported();
        $this->relay();

        $this->login()->get('/admin/zatca?period=all')->assertOk()
            ->assertSee('B2B · Clearance')->assertSee('B2C · Reporting')
            ->assertSee('Active devices');
    }

    public function test_lists_filter_by_tab_search_and_type(): void
    {
        $this->issue('UI-ACC');
        $this->zatca->reported();
        $this->relay();
        $this->issue('UI-REJ');
        $this->zatca->notReported();
        $this->relay();

        $this->login()->get('/admin/zatca/invoices/b2c')->assertOk()->assertSee('UI-ACC')->assertSee('UI-REJ');
        $this->login()->get('/admin/zatca/invoices/b2c?tab=rejected')->assertOk()->assertSee('UI-REJ')->assertDontSee('UI-ACC');
        $this->login()->get('/admin/zatca/invoices/b2c?q=UI-AC')->assertOk()->assertSee('UI-ACC')->assertDontSee('UI-REJ');
        $this->login()->get('/admin/zatca/invoices/b2c?type=381')->assertOk()->assertSee('No invoices match.');
        $this->login()->get('/admin/zatca/invoices/b2b')->assertOk()->assertSee('No invoices match.');
        $this->login()->get('/admin/zatca/invoices/xyz')->assertNotFound();
    }

    public function test_every_detail_tab_renders(): void
    {
        $result = $this->issue('UI-TABS', [['name' => 'Shirt', 'quantity' => '3', 'unit_price' => '100', 'vat_category' => 'S', 'vat_rate' => '15',
            'discounts' => [['percent' => '10', 'reason' => 'Member']]]]);
        $this->zatca->notReported();
        $this->relay();
        $id = $result['invoice_id'];

        $expect = [
            'summary' => 'Current UUID', 'lines' => '270.00', 'versions' => '&quot;invoice_number&quot;: &quot;UI-TABS&quot;',
            'issuances' => 'Download signed xml', 'attempts' => 'BR-KSA-37', 'errors' => 'Invalid seller VAT',
            'history' => 'REJECTED', 'related' => 'No credit or debit notes.',
        ];
        foreach ($expect as $tab => $text) {
            $this->login()->get("/admin/zatca/invoice/{$id}?tab={$tab}")->assertOk()->assertSee($text, false);
        }
    }

    public function test_detail_never_shows_secrets(): void
    {
        $id = $this->issue('UI-SEC')['invoice_id'];
        $page = '';
        foreach (['summary', 'issuances', 'attempts', 'history'] as $tab) {
            $page .= $this->login()->get("/admin/zatca/invoice/{$id}?tab={$tab}")->getContent();
        }

        self::assertStringNotContainsString('PRIVATE KEY', $page);
        self::assertStringNotContainsString('production-secret', $page);
        self::assertStringNotContainsString('compliance-secret', $page);
    }

    // ---------------------------------------------------------------- actions

    public function test_cancel_action_issues_a_credit_note(): void
    {
        $id = $this->issue('UI-CAN')['invoice_id'];
        $this->zatca->reported();
        $this->relay();

        $this->login()->get("/admin/zatca/invoice/{$id}")->assertSee('Cancel (credit note)');
        $this->login()->post("/admin/zatca/invoice/{$id}/cancel", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->login()->post("/admin/zatca/invoice/{$id}/cancel", ['reason' => 'Customer cancelled'])
            ->assertRedirect("/admin/zatca/invoice/{$id}")->assertSessionHas('zatca_status');

        $original = Invoice::query()->where('public_id', $id)->sole();
        self::assertNotNull($original->cancellation_invoice_id);
        self::assertSame(5, (int) $original->cancellationNote->created_by, 'the logged-in user is the actor');

        $audit = \CodingTriplets\Zatca\Laravel\Database\Models\AuditLog::query()->where('action', 'invoice.cancel')->sole();
        self::assertSame(5, (int) $audit->actor_id);
        self::assertSame((int) $original->id, (int) $audit->subject_id);
        self::assertSame('Customer cancelled', $audit->changes['reason']);
        self::assertSame($original->cancellationNote->public_id, $audit->changes['document']);
        self::assertTrue(app(\CodingTriplets\Zatca\Laravel\Audit\AuditLogger::class)->verifyChain()['valid']);
    }

    public function test_actions_need_their_own_ability(): void
    {
        $id = $this->issue('UI-PERM')['invoice_id'];
        $this->zatca->reported();
        $this->relay();

        $this->granted = [Abilities::DASHBOARD_VIEW, Abilities::B2C_VIEW];
        $this->login()->get("/admin/zatca/invoice/{$id}")->assertOk()->assertDontSee('Cancel (credit note)');
        $this->login()->post("/admin/zatca/invoice/{$id}/cancel", ['reason' => 'x'])->assertForbidden();
        self::assertNull(Invoice::query()->where('public_id', $id)->value('cancellation_invoice_id'));
    }

    public function test_abandon_and_discard_actions(): void
    {
        $rejected = $this->issue('UI-ABN')['invoice_id'];
        $this->zatca->notReported();
        $this->relay();
        $this->login()->get("/admin/zatca/invoice/{$rejected}")->assertSee('Abandon')->assertSee('Reissue unchanged');
        $this->login()->post("/admin/zatca/invoice/{$rejected}/abandon", ['reason' => 'Sale reversed'])->assertRedirect();
        self::assertSame(ProcessingStatus::Abandoned, Invoice::query()->where('public_id', $rejected)->sole()->processing_status);

        $draft = app(ZatcaManager::class)->b2b()->submit($this->payload('UI-DIS') + $this->b2bExtras(), ['device' => $this->device->id])->toArray();
        $this->login()->post("/admin/zatca/invoice/{$draft['invoice_id']}/discard", ['reason' => 'Twice'])->assertRedirect();
        self::assertSame(ProcessingStatus::Cancelled, Invoice::query()->where('public_id', $draft['invoice_id'])->sole()->processing_status);

        // an operation that does not fit the state comes back as a flash error, not a 500
        $this->login()->post("/admin/zatca/invoice/{$rejected}/retry")->assertRedirect()->assertSessionHas('zatca_error');
        $this->login()->post("/admin/zatca/invoice/{$rejected}/explode")->assertNotFound();
    }

    public function test_xml_download_needs_the_export_ability(): void
    {
        $id = $this->issue('UI-XML')['invoice_id'];
        $artifact = InvoiceArtifact::query()->where('invoice_id', Invoice::query()->where('public_id', $id)->value('id'))->firstOrFail();

        $response = $this->login()->get("/admin/zatca/invoice/{$id}/artifact/{$artifact->id}");
        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        self::assertStringContainsString('<cbc:ID>UI-XML</cbc:ID>', $response->getContent());
        self::assertStringContainsString('attachment; filename="UI-XML_signed_xml.xml"', (string) $response->headers->get('Content-Disposition'));

        $this->granted = [Abilities::DASHBOARD_VIEW, Abilities::B2C_VIEW];
        $this->login()->get("/admin/zatca/invoice/{$id}/artifact/{$artifact->id}")->assertForbidden();
    }

    public function test_css_asset_is_served_without_login(): void
    {
        $this->get('/admin/zatca/assets/zatca.css')->assertOk()->assertHeader('Content-Type', 'text/css; charset=UTF-8');
        $this->get('/admin/zatca/assets/secret.php')->assertNotFound();
    }

    // ---------------------------------------------------------------- helpers

    private function login(): self
    {
        return $this->actingAs(new GenericUser(['id' => 5, 'name' => 'Operator']));
    }

    /** @param list<array<string, mixed>>|null $lines */
    private function issue(string $number, ?array $lines = null): array
    {
        $payload = $this->payload($number);
        if ($lines !== null) {
            $payload['lines'] = $lines;
        }

        return app(ZatcaManager::class)->b2c()->issue($payload, ['device' => $this->device->id])->toArray();
    }

    /** @return array<string, mixed> */
    private function payload(string $number): array
    {
        return ['invoice_number' => $number, 'lines' => [['name' => 'Coffee', 'quantity' => '2', 'unit_price' => '10.00', 'vat_category' => 'S', 'vat_rate' => '15']]];
    }

    /** @return array<string, mixed> */
    private function b2bExtras(): array
    {
        return [
            'supply_date' => '2026-11-01',
            'buyer' => ['name' => 'Fatoora Samples LTD', 'vat_number' => '399999999800003', 'address' => ['street' => 'Salah Al-Din', 'building_number' => '1111', 'district' => 'Al-Murooj', 'city' => 'Riyadh', 'postal_code' => '12222', 'country' => 'SA']],
        ];
    }

    private function relay(): void
    {
        app(OutboxRelay::class)->relay();
    }
}
