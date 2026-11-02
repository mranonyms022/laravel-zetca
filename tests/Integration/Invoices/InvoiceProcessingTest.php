<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Tests\Integration\Invoices;

use CodingTriplets\Zatca\Core\Contracts\Storage\ArtifactStore;
use CodingTriplets\Zatca\Core\Domain\Enums\ArtifactKind;
use CodingTriplets\Zatca\Core\Domain\Enums\IssuanceStatus;
use CodingTriplets\Zatca\Core\Domain\Enums\ProcessingStatus;
use CodingTriplets\Zatca\Core\Domain\Enums\ReportingStatus;
use CodingTriplets\Zatca\Core\Domain\Enums\ClearanceStatus;
use CodingTriplets\Zatca\Core\Events\InvoiceAccepted;
use CodingTriplets\Zatca\Core\Events\InvoiceIssued;
use CodingTriplets\Zatca\Core\Events\InvoiceRejected;
use CodingTriplets\Zatca\Laravel\Database\Models\B2cReportingSubmission;
use CodingTriplets\Zatca\Laravel\Database\Models\ChainEntry;
use CodingTriplets\Zatca\Laravel\Database\Models\Device;
use CodingTriplets\Zatca\Laravel\Database\Models\Invoice;
use CodingTriplets\Zatca\Laravel\Database\Models\InvoiceArtifact;
use CodingTriplets\Zatca\Laravel\Database\Models\InvoiceCounter;
use CodingTriplets\Zatca\Laravel\Database\Models\InvoiceIssuance;
use CodingTriplets\Zatca\Laravel\Database\Models\OutboxMessage;
use CodingTriplets\Zatca\Laravel\Database\Models\SubmissionAttempt;
use CodingTriplets\Zatca\Laravel\Database\Models\ZatcaError;
use CodingTriplets\Zatca\Laravel\Devices\DeviceOnboardingService;
use CodingTriplets\Zatca\Laravel\Devices\DeviceRegistrar;
use CodingTriplets\Zatca\Laravel\Fatoora\FatooraClientFactory;
use CodingTriplets\Zatca\Laravel\Invoices\DuplicateInvoice;
use CodingTriplets\Zatca\Laravel\Processing\ChainVerifier;
use CodingTriplets\Zatca\Laravel\Processing\Outbox\OutboxRelay;
use CodingTriplets\Zatca\Core\Fatoora\BasicAuthentication;
use CodingTriplets\Zatca\Laravel\ZatcaManager;
use CodingTriplets\Zatca\Core\Contracts\Storage\StoredArtifact;
use CodingTriplets\Zatca\Tests\Integration\IntegrationTestCase;
use CodingTriplets\Zatca\Tests\Support\FakeZatca;
use Illuminate\Support\Facades\Event;

/** End to end: intake → validation → ICV/PIH issuance → outbox → queue → reporting/clearance → outcome. Only ZATCA's HTTP is faked. */
final class InvoiceProcessingTest extends IntegrationTestCase
{
    use \CodingTriplets\Zatca\Tests\Integration\Support\OnboardsDevices;

    private FakeZatca $zatca;

    private Device $device;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = sys_get_temp_dir() . '/zatca-test-' . bin2hex(random_bytes(4));
        config([
            'filesystems.disks.zatca_test' => ['driver' => 'local', 'root' => $this->storage],
            'zatca.storage.disk' => 'zatca_test',
            'zatca.devices.sandbox_skip_compliance_checks' => true,
            'zatca.retry.b2c.backoff_seconds' => [30, 60],
        ]);
        $this->zatca = new FakeZatca();
        $this->app->instance(FatooraClientFactory::class, new FatooraClientFactory(new BasicAuthentication(), $this->zatca->stack));
        $this->device = $this->activeDevice();
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

    public function test_queue_mode_returns_qr_and_counter_without_calling_zatca(): void
    {
        Event::fake([InvoiceIssued::class]);
        $requestsBefore = count($this->zatca->history);

        $result = $this->zatca()->b2c()->issue($this->payload('POS-1'), ['device' => $this->device->id]);
        $json = $result->toArray();

        self::assertTrue($json['status']);
        self::assertSame('invoice queued', $json['message']);
        self::assertSame(1, $json['counter']);
        self::assertNotEmpty($json['qr']);
        self::assertNotFalse(base64_decode($json['qr'], true));
        self::assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $json['invoice_id']);
        self::assertArrayHasKey('reporting_deadline', $json);
        self::assertSame($requestsBefore, count($this->zatca->history), 'issuance must not call ZATCA');

        $invoice = Invoice::query()->where('public_id', $json['invoice_id'])->firstOrFail();
        self::assertSame(ProcessingStatus::Queued, $invoice->processing_status);
        self::assertSame(1, OutboxMessage::query()->where('event_type', 'submit')->whereNull('processed_at')->count());
        self::assertSame(1, (int) InvoiceCounter::query()->where('device_id', $this->device->id)->value('last_icv'));
        Event::assertDispatched(InvoiceIssued::class, static fn (InvoiceIssued $e) => $e->counter === 1);
    }

    public function test_queued_invoice_is_reported_and_details_saved(): void
    {
        Event::fake([InvoiceAccepted::class]);
        $json = $this->zatca()->b2c()->issue($this->payload('POS-1'), ['device' => $this->device->id])->toArray();
        $this->zatca->reported();

        $relayed = app(OutboxRelay::class)->relay();   // sync queue driver in tests: the job runs here

        self::assertSame(1, $relayed['dispatched']);
        $invoice = Invoice::query()->where('public_id', $json['invoice_id'])->firstOrFail();
        self::assertSame(ProcessingStatus::Reported, $invoice->processing_status);
        self::assertSame(ReportingStatus::Reported, $invoice->reporting_status);
        self::assertNotNull($invoice->reported_at);
        self::assertSame(IssuanceStatus::Accepted, InvoiceIssuance::query()->findOrFail($invoice->current_issuance_id)->status);
        self::assertSame('reported', B2cReportingSubmission::query()->where('invoice_id', $invoice->id)->value('status'));

        $attempt = SubmissionAttempt::query()->where('invoice_id', $invoice->id)->sole();
        self::assertSame(200, (int) $attempt->http_status);
        self::assertSame('[redacted]', $attempt->request_headers['Authorization']);

        $request = $this->zatca->request(count($this->zatca->history) - 1);
        self::assertStringEndsWith('/invoices/reporting/single', $request->getUri()->getPath());
        self::assertSame('0', $request->getHeaderLine('Clearance-Status'));
        $body = json_decode((string) $request->getBody(), true);
        self::assertSame($json['uuid'], $body['uuid']);
        self::assertSame($json['invoice_hash'], $body['invoiceHash']);
        Event::assertDispatched(InvoiceAccepted::class);

        // already final: relaying again sends nothing
        self::assertSame(0, app(OutboxRelay::class)->relay()['dispatched']);
    }

    public function test_client_retry_is_idempotent_and_a_changed_payload_is_refused(): void
    {
        $first = $this->zatca()->b2c()->issue($this->payload('POS-7'), ['device' => $this->device->id])->toArray();
        $again = $this->zatca()->b2c()->issue($this->payload('POS-7'), ['device' => $this->device->id])->toArray();

        self::assertSame($first['counter'], $again['counter']);
        self::assertSame($first['uuid'], $again['uuid']);
        self::assertSame(1, InvoiceIssuance::query()->count());

        $this->expectException(DuplicateInvoice::class);
        $this->zatca()->b2c()->issue($this->payload('POS-7', '99.00'), ['device' => $this->device->id]);
    }

    public function test_chain_is_contiguous_and_verifiable(): void
    {
        $counters = [];
        for ($i = 1; $i <= 5; $i++) {
            $counters[] = $this->zatca()->b2c()->issue($this->payload("POS-{$i}"), ['device' => $this->device->id])->toArray()['counter'];
        }

        self::assertSame([1, 2, 3, 4, 5], $counters);
        $result = app(ChainVerifier::class)->verify((int) $this->device->id, 'sandbox', deep: true, record: false);
        self::assertSame([], $result['problems']);
        self::assertSame(5, $result['entries']);
    }

    public function test_invalid_invoice_uses_no_counter(): void
    {
        $payload = $this->payload('POS-BAD');
        $payload['lines'] = [];

        $json = $this->zatca()->b2c()->issue($payload, ['device' => $this->device->id])->toArray();

        self::assertFalse($json['status']);
        self::assertSame('validation failed', $json['message']);
        self::assertNotEmpty($json['errors']);
        self::assertSame(ProcessingStatus::ValidationFailed, Invoice::query()->sole()->processing_status);
        self::assertSame(0, (int) InvoiceCounter::query()->where('device_id', $this->device->id)->value('last_icv'));
        self::assertSame(0, ChainEntry::query()->count());

        $next = $this->zatca()->b2c()->issue($this->payload('POS-GOOD'), ['device' => $this->device->id])->toArray();
        self::assertSame(1, $next['counter']);
    }

    public function test_storage_failure_rolls_back_and_does_not_consume_the_counter(): void
    {
        $real = app(ArtifactStore::class);
        $this->app->instance(ArtifactStore::class, new class implements ArtifactStore {
            public function putImmutable(string $namespace, string $contents, string $extension = 'xml'): StoredArtifact
            {
                throw new \RuntimeException('disk full');
            }

            public function get(StoredArtifact $artifact): string
            {
                throw new \RuntimeException('unused');
            }

            public function exists(StoredArtifact $artifact): bool
            {
                return false;
            }
        });
        $this->forgetProcessingServices();

        try {
            $this->zatca()->b2c()->issue($this->payload('POS-1'), ['device' => $this->device->id]);
            self::fail('expected failure');
        } catch (\RuntimeException $e) {
            self::assertSame('disk full', $e->getMessage());
        }

        self::assertSame(0, ChainEntry::query()->count());
        self::assertSame(0, InvoiceIssuance::query()->count());
        self::assertSame(0, (int) InvoiceCounter::query()->where('device_id', $this->device->id)->value('last_icv'));
        self::assertSame(ProcessingStatus::ReadyForProcessing, Invoice::query()->sole()->processing_status);

        $this->app->instance(ArtifactStore::class, $real);
        $this->forgetProcessingServices();
        $issued = app(\CodingTriplets\Zatca\Laravel\Invoices\InvoiceProcessor::class)->process((int) Invoice::query()->sole()->id);
        self::assertSame(1, (int) $issued?->icv);
    }

    public function test_rejection_is_recorded_and_never_retried(): void
    {
        Event::fake([InvoiceRejected::class]);
        $json = $this->zatca()->b2c()->issue($this->payload('POS-1'), ['device' => $this->device->id])->toArray();
        $this->zatca->notReported();

        app(OutboxRelay::class)->relay();

        $invoice = Invoice::query()->where('public_id', $json['invoice_id'])->firstOrFail();
        self::assertSame(ProcessingStatus::Rejected, $invoice->processing_status);
        self::assertSame(ReportingStatus::NotReported, $invoice->reporting_status);
        self::assertSame(IssuanceStatus::Rejected, InvoiceIssuance::query()->findOrFail($invoice->current_issuance_id)->status);
        self::assertSame('BR-KSA-37', ZatcaError::query()->where('invoice_id', $invoice->id)->value('code'));
        self::assertSame(0, OutboxMessage::query()->whereNull('processed_at')->count());
        Event::assertDispatched(InvoiceRejected::class);

        $show = $this->zatca()->b2c()->issue($this->payload('POS-1'), ['device' => $this->device->id])->toArray();
        self::assertSame('invoice not reported', $show['message']);
        self::assertSame('BR-KSA-37', $show['errors'][0]['code']);
    }

    public function test_transient_failure_is_retried_with_the_same_document(): void
    {
        $json = $this->zatca()->b2c()->issue($this->payload('POS-1'), ['device' => $this->device->id])->toArray();
        $this->zatca->error(503, ['message' => 'Service unavailable']);

        app(OutboxRelay::class)->relay();

        $invoice = Invoice::query()->where('public_id', $json['invoice_id'])->firstOrFail();
        self::assertSame(ProcessingStatus::RetryPending, $invoice->processing_status);
        self::assertNotNull($invoice->next_retry_at);
        $retry = OutboxMessage::query()->whereNull('processed_at')->sole();
        self::assertTrue($retry->available_at->isFuture());

        // time passes: the retry resends the identical signed document
        $this->travel(2)->minutes();
        $this->zatca->reported();
        app(OutboxRelay::class)->relay();

        $invoice->refresh();
        self::assertSame(ProcessingStatus::Reported, $invoice->processing_status);
        $bodies = array_map(static fn ($h) => json_decode((string) $h['request']->getBody(), true), array_slice($this->zatca->history, -2));
        self::assertSame($bodies[0], $bodies[1]);
        self::assertSame(2, SubmissionAttempt::query()->where('invoice_id', $invoice->id)->count());
        self::assertSame(1, InvoiceIssuance::query()->count(), 'a retry never creates a new ICV');
    }

    public function test_sync_mode_reports_inside_the_request(): void
    {
        config(['zatca.b2c.reporting_mode' => 'sync']);
        $this->zatca->reported();

        $json = $this->zatca()->b2c()->issue($this->payload('POS-1'), ['device' => $this->device->id])->toArray();

        self::assertSame('invoice reported', $json['message']);
        self::assertSame('REPORTED', $json['processing_status']);
        // the safety-net message is deferred and becomes a no-op
        self::assertSame(0, app(OutboxRelay::class)->relay()['dispatched']);
    }

    public function test_b2b_is_cleared_and_the_cleared_xml_is_stored(): void
    {
        $result = $this->zatca()->b2b()->submit($this->payload('B2B-1', b2b: true), ['device' => $this->device->id])->toArray();
        self::assertTrue($result['status']);
        self::assertSame('READY_FOR_PROCESSING', $result['processing_status']);

        app(OutboxRelay::class)->relay();     // generation → issuance (records the submit message)
        $this->zatca->cleared('<Invoice>cleared by zatca</Invoice>');
        app(OutboxRelay::class)->relay();     // clearance

        $invoice = Invoice::query()->where('public_id', $result['invoice_id'])->firstOrFail();
        self::assertSame(ProcessingStatus::Cleared, $invoice->processing_status);
        self::assertSame(ClearanceStatus::Cleared, $invoice->clearance_status);
        $request = $this->zatca->request(count($this->zatca->history) - 1);
        self::assertStringEndsWith('/invoices/clearance/single', $request->getUri()->getPath());
        self::assertSame('1', $request->getHeaderLine('Clearance-Status'));

        $cleared = InvoiceArtifact::query()->where('invoice_id', $invoice->id)->where('kind', ArtifactKind::ClearedXml)->sole();
        self::assertSame('<Invoice>cleared by zatca</Invoice>', app(ArtifactStore::class)->get(new StoredArtifact($cleared->disk, $cleared->path, $cleared->sha256, (int) $cleared->bytes, (bool) $cleared->encrypted)));
    }

    public function test_http_api_returns_the_queue_contract(): void
    {
        config(['zatca.api.enabled' => true]);
        $this->refreshRoutes();

        $response = $this->postJson('/api/zatca/b2c/invoices', $this->payload('API-1'), ['X-Zatca-Device' => (string) $this->device->id]);

        $response->assertStatus(201)->assertJson(['status' => true, 'message' => 'invoice queued', 'counter' => 1]);
        self::assertNotEmpty($response->json('qr'));

        $this->postJson('/api/zatca/b2c/invoices', $this->payload('API-1'), ['X-Zatca-Device' => (string) $this->device->id])
            ->assertStatus(200)->assertJson(['counter' => 1]);
        $this->postJson('/api/zatca/b2c/invoices', $this->payload('API-1', '5.00'), ['X-Zatca-Device' => (string) $this->device->id])
            ->assertStatus(409);
        $this->getJson('/api/zatca/invoices/' . $response->json('invoice_id'))->assertOk()->assertJson(['counter' => 1]);
    }

    private function zatca(): ZatcaManager
    {
        return app(ZatcaManager::class);
    }

    /** @return array<string, mixed> */
    private function payload(string $number, string $price = '10.00', bool $b2b = false): array
    {
        $payload = [
            'invoice_number' => $number,
            'lines' => [['name' => 'Coffee', 'quantity' => '2', 'unit_price' => $price, 'vat_category' => 'S', 'vat_rate' => '15']],
        ];
        if ($b2b) {
            $payload['supply_date'] = now()->format('Y-m-d');
            $payload['buyer'] = [
                'name' => 'Fatoora Samples LTD',
                'vat_number' => '399999999800003',
                'address' => ['street' => 'Salah Al-Din', 'building_number' => '1111', 'district' => 'Al-Murooj', 'city' => 'Riyadh', 'postal_code' => '12222', 'country' => 'SA'],
            ];
        }

        return $payload;
    }

    private function forgetProcessingServices(): void
    {
        foreach ([\CodingTriplets\Zatca\Laravel\Invoices\ChainService::class, \CodingTriplets\Zatca\Laravel\Invoices\InvoiceProcessor::class] as $class) {
            $this->app->forgetInstance($class);
        }
    }

    private function refreshRoutes(): void
    {
        require __DIR__ . '/../../../routes/api.php';
        $this->app['router']->getRoutes()->refreshNameLookups();
    }
}
