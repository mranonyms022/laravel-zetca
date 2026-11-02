<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Tests\Integration\Invoices;

use CodingTriplets\Zatca\Core\Contracts\Storage\ArtifactStore;
use CodingTriplets\Zatca\Core\Contracts\Storage\StoredArtifact;
use CodingTriplets\Zatca\Core\Domain\Enums\ArtifactKind;
use CodingTriplets\Zatca\Core\Domain\Enums\ClearanceStatus;
use CodingTriplets\Zatca\Core\Domain\Enums\DocumentType;
use CodingTriplets\Zatca\Core\Domain\Enums\IssuanceStatus;
use CodingTriplets\Zatca\Core\Domain\Enums\ProcessingStatus;
use CodingTriplets\Zatca\Core\Domain\Enums\ReportingStatus;
use CodingTriplets\Zatca\Core\Events\ClearanceDeactivated;
use CodingTriplets\Zatca\Core\Events\CustomerCopyReissued;
use CodingTriplets\Zatca\Core\Events\InvoiceReissued;
use CodingTriplets\Zatca\Core\Events\InvoiceReportingDeadlineAtRisk;
use CodingTriplets\Zatca\Core\Events\InvoiceReportingDeadlineBreached;
use CodingTriplets\Zatca\Core\Fatoora\BasicAuthentication;
use CodingTriplets\Zatca\Core\Invoice\Shared\Validation\InvoiceValidationException;
use CodingTriplets\Zatca\Laravel\Database\Models\B2cReportingSubmission;
use CodingTriplets\Zatca\Laravel\Database\Models\Device;
use CodingTriplets\Zatca\Laravel\Database\Models\Invoice;
use CodingTriplets\Zatca\Laravel\Database\Models\InvoiceArtifact;
use CodingTriplets\Zatca\Laravel\Database\Models\InvoiceIssuance;
use CodingTriplets\Zatca\Laravel\Database\Models\InvoiceVersion;
use CodingTriplets\Zatca\Laravel\Database\Models\OutboxMessage;
use CodingTriplets\Zatca\Laravel\Database\Models\ZatcaError;
use CodingTriplets\Zatca\Laravel\Fatoora\FatooraClientFactory;
use CodingTriplets\Zatca\Laravel\Invoices\InvoiceIntakeService;
use CodingTriplets\Zatca\Laravel\Invoices\OperationNotAllowed;
use CodingTriplets\Zatca\Laravel\Processing\ChainVerifier;
use CodingTriplets\Zatca\Laravel\Processing\DeadlineMonitor;
use CodingTriplets\Zatca\Laravel\Processing\Outbox\OutboxRelay;
use CodingTriplets\Zatca\Laravel\Processing\Reconciler;
use CodingTriplets\Zatca\Laravel\ZatcaManager;
use CodingTriplets\Zatca\Core\Domain\Enums\Workflow;
use CodingTriplets\Zatca\Tests\Integration\IntegrationTestCase;
use CodingTriplets\Zatca\Tests\Integration\Support\OnboardsDevices;
use CodingTriplets\Zatca\Tests\Support\FakeZatca;
use Illuminate\Support\Facades\Event;

/** Operations A (retry), B (correct / reissue / approve), C (credit / debit notes), 303, deadlines, reconciliation. */
final class InvoiceOperationsTest extends IntegrationTestCase
{
    use OnboardsDevices;

    private FakeZatca $zatca;

    private Device $device;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir() . '/zatca-ops-' . bin2hex(random_bytes(4));
        config([
            'filesystems.disks.zatca_test' => ['driver' => 'local', 'root' => $this->storage],
            'zatca.storage.disk' => 'zatca_test',
            'zatca.devices.sandbox_skip_compliance_checks' => true,
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

    // ---------------------------------------------------------------- B: correct & reprocess

    public function test_rejected_b2c_invoice_is_corrected_on_the_same_record_and_reported(): void
    {
        Event::fake([InvoiceReissued::class, CustomerCopyReissued::class]);
        $first = $this->issueB2c('POS-1', ['issued_at' => '2026-11-02T08:15:00']);
        $this->zatca->notReported();
        $this->relay();
        $invoice = $this->invoice($first['invoice_id']);
        self::assertSame(ProcessingStatus::Rejected, $invoice->processing_status);
        self::assertSame(1, $invoice->unresolved_error_count);

        $result = $this->ops()->correct($invoice, $this->payload('POS-1', '11.00', ['issued_at' => '2026-11-02T08:15:00']), 'Price was wrong', 5)->toArray();

        self::assertSame($first['invoice_id'], $result['invoice_id'], 'same internal record');
        self::assertSame(2, $result['counter'], 'next ICV');
        self::assertNotSame($first['uuid'], $result['uuid'], 'new UUID');
        $invoice->refresh();
        self::assertSame('POS-1', $invoice->invoice_number, 'B2C keeps the invoice number ⚠');
        self::assertSame(2, $invoice->issuance_count);
        self::assertSame(0, $invoice->unresolved_error_count, 'the rejection errors are resolved by the reissue');
        self::assertSame(ReportingStatus::Pending, $invoice->reporting_status);

        $old = InvoiceIssuance::query()->where('uuid', $first['uuid'])->sole();
        $new = InvoiceIssuance::query()->where('uuid', $result['uuid'])->sole();
        self::assertSame(IssuanceStatus::Superseded, $old->status);
        self::assertSame((int) $old->id, (int) $new->supersedes_issuance_id);
        self::assertSame($old->invoice_hash, $new->previous_invoice_hash, 'PIH = chain head (the rejected document stays in the chain)');
        self::assertSame('08:15:00', $new->issue_time, 'B2C keeps the original issue date/time ⚠');

        $version = InvoiceVersion::query()->where('invoice_id', $invoice->id)->where('version', 2)->sole();
        self::assertSame('Price was wrong', $version->reason);
        self::assertContains('/lines/0/unit_price', $version->changed_fields);
        self::assertSame(5, (int) $version->created_by);
        self::assertSame((int) $old->id, (int) InvoiceVersion::query()->where('invoice_id', $invoice->id)->where('version', 1)->value('issuance_id'));
        self::assertSame((int) $new->id, (int) $version->issuance_id);

        $this->zatca->reported();
        $this->relay();
        self::assertSame(ProcessingStatus::Reported, $invoice->refresh()->processing_status);
        self::assertSame([], app(ChainVerifier::class)->verify((int) $this->device->id, 'sandbox', deep: true, record: false)['problems']);
        Event::assertDispatched(InvoiceReissued::class, static fn (InvoiceReissued $e) => $e->counter === 2 && $e->actorId === 5);
        Event::assertDispatched(CustomerCopyReissued::class);
    }

    public function test_rejected_b2b_reissue_uses_the_reissue_time_as_issue_date(): void
    {
        $first = $this->zatcaManager()->b2b()->issue($this->payload('B2B-1', '10.00', $this->b2bExtras() + ['issued_at' => '2026-11-01T09:00:00']), ['device' => $this->device->id])->toArray();
        $this->zatca->notCleared();
        $this->relay();
        $invoice = $this->invoice($first['invoice_id']);
        self::assertSame(ClearanceStatus::NotCleared, $invoice->clearance_status);

        $this->ops()->correct($invoice, $this->payload('B2B-1', '10.00', $this->b2bExtras() + ['issued_at' => '2026-11-01T09:00:00']), 'Buyer ID fixed');

        $new = InvoiceIssuance::query()->findOrFail($invoice->refresh()->current_issuance_id);
        self::assertSame(now('Asia/Riyadh')->format('Y-m-d'), \Illuminate\Support\Carbon::parse($new->issue_date)->format('Y-m-d'), 'B2B reissue date = now ⚠');
        $this->zatca->cleared();
        $this->relay();
        self::assertSame(ProcessingStatus::Cleared, $invoice->refresh()->processing_status);
    }

    public function test_invalid_correction_saves_nothing(): void
    {
        $first = $this->issueB2c('POS-1');
        $this->zatca->notReported();
        $this->relay();
        $bad = $this->payload('POS-1');
        $bad['lines'] = [];

        try {
            $this->ops()->correct($first['invoice_id'], $bad, 'try');
            self::fail('expected validation failure');
        } catch (InvoiceValidationException $e) {
            self::assertNotEmpty($e->result->errors());
        }
        self::assertSame(1, InvoiceVersion::query()->count());
        self::assertSame(1, InvoiceIssuance::query()->count());
    }

    public function test_validation_failed_invoice_is_corrected_before_any_issuance(): void
    {
        $payload = $this->payload('POS-9');
        $payload['lines'] = [];
        $failed = $this->zatcaManager()->b2c()->issue($payload, ['device' => $this->device->id])->toArray();
        self::assertFalse($failed['status']);

        $result = $this->ops()->correct($failed['invoice_id'], $this->payload('POS-9'), 'Lines added')->toArray();

        self::assertTrue($result['status']);
        self::assertSame(1, $result['counter']);
        self::assertSame(0, $this->invoice($failed['invoice_id'])->unresolved_error_count);
    }

    public function test_reissue_unchanged_creates_a_new_issuance_from_the_same_version(): void
    {
        $first = $this->issueB2c('POS-1');
        $this->zatca->notReported([['code' => 'signature', 'message' => 'Invalid signature']]);
        $this->relay();

        $result = $this->ops()->reissueUnchanged($first['invoice_id'], 'Certificate replaced')->toArray();

        self::assertSame(2, $result['counter']);
        $issuances = InvoiceIssuance::query()->orderBy('id')->get();
        self::assertSame($issuances[0]->version_id, $issuances[1]->version_id);
        self::assertSame('reissue_unchanged', $issuances[1]->reason->value);
    }

    public function test_four_eyes_requires_a_different_approver(): void
    {
        config(['zatca.correction.four_eyes' => true]);
        $first = $this->issueB2c('POS-1');
        $this->zatca->notReported();
        $this->relay();

        $held = $this->ops()->correct($first['invoice_id'], $this->payload('POS-1', '12.00'), 'Fix', 7)->toArray();
        self::assertSame('MANUAL_REVIEW', $held['processing_status']);
        self::assertSame(1, InvoiceIssuance::query()->count(), 'nothing issued before approval');

        try {
            $this->ops()->approve($first['invoice_id'], 7);
            self::fail('the corrector cannot approve');
        } catch (OperationNotAllowed) {
        }

        $approved = $this->ops()->approve($first['invoice_id'], 8)->toArray();
        self::assertSame(2, $approved['counter']);
        self::assertSame(8, (int) $this->invoice($first['invoice_id'])->approved_by);
    }

    public function test_accepted_invoice_cannot_be_corrected_or_reissued(): void
    {
        $first = $this->issueB2c('POS-1');
        $this->zatca->reported();
        $this->relay();

        $this->expectException(OperationNotAllowed::class);
        $this->ops()->correct($first['invoice_id'], $this->payload('POS-1', '1.00'), 'nope');
    }

    public function test_policy_can_require_a_credit_note_for_rejected_documents(): void
    {
        config(['zatca.correction.credit_note_for_rejected' => true]);
        $first = $this->issueB2c('POS-1');
        $this->zatca->notReported();
        $this->relay();

        $this->expectException(OperationNotAllowed::class);
        $this->ops()->correct($first['invoice_id'], $this->payload('POS-1', '11.00'), 'fix');
    }

    // ---------------------------------------------------------------- C: credit / debit notes

    public function test_credit_and_debit_notes_reference_the_accepted_invoice(): void
    {
        $first = $this->issueB2c('POS-1');
        $this->zatca->reported();
        $this->relay();

        $credit = $this->ops()->creditNote($first['invoice_id'], $this->payload('POS-1-CN', '10.00'), 'Goods returned')->toArray();
        $debit = $this->ops()->debitNote($first['invoice_id'], $this->payload('POS-1-DN', '2.00'), 'Price adjustment')->toArray();

        self::assertSame([2, 3], [$credit['counter'], $debit['counter']], 'notes share the device chain');
        $note = $this->invoice($credit['invoice_id']);
        self::assertSame(DocumentType::CreditNote, $note->document_type);
        self::assertSame(Workflow::B2C, $note->workflow);
        self::assertSame((int) $this->invoice($first['invoice_id'])->id, (int) $note->billing_reference_invoice_id);
        self::assertSame(DocumentType::DebitNote, $this->invoice($debit['invoice_id'])->document_type);

        $xml = $this->signedXml((int) $note->current_issuance_id);
        self::assertStringContainsString('<cbc:InvoiceTypeCode name="0200000">381</cbc:InvoiceTypeCode>', $xml);
        self::assertMatchesRegularExpression('#<cac:BillingReference>\s*<cac:InvoiceDocumentReference>\s*<cbc:ID>POS-1</cbc:ID>#', $xml);
        self::assertStringContainsString('Goods returned', $xml);
    }

    public function test_note_requires_an_accepted_invoice(): void
    {
        $first = $this->issueB2c('POS-1');
        $this->zatca->notReported();
        $this->relay();

        $this->expectException(OperationNotAllowed::class);
        $this->ops()->creditNote($first['invoice_id'], $this->payload('POS-1-CN'), 'Cancel');
    }

    // ---------------------------------------------------------------- A: retry

    public function test_manual_retry_resends_the_identical_document(): void
    {
        config(['zatca.retry.b2c' => ['max_attempts' => 1, 'backoff_seconds' => [30]]]);
        $first = $this->issueB2c('POS-1');
        $this->zatca->error(503, ['message' => 'down']);
        $this->relay();
        self::assertSame(ProcessingStatus::Failed, $this->invoice($first['invoice_id'])->processing_status, 'attempts exhausted');

        $this->zatca->reported();
        $status = $this->ops()->retry($first['invoice_id'], 3, now: true);

        self::assertSame('reported', $status);
        self::assertSame(1, InvoiceIssuance::query()->count());
        $bodies = array_map(static fn ($h) => (string) $h['request']->getBody(), array_slice($this->zatca->history, -2));
        self::assertSame($bodies[0], $bodies[1]);

        $this->expectException(OperationNotAllowed::class);
        $this->ops()->retry($first['invoice_id']);
    }

    public function test_unexpected_error_during_submission_becomes_an_unknown_outcome_not_a_stuck_claim(): void
    {
        $first = $this->issueB2c('POS-1');   // no fake response queued: the HTTP layer throws OutOfBoundsException

        $this->relay();

        $invoice = $this->invoice($first['invoice_id']);
        self::assertSame(ProcessingStatus::OutcomeUnknown, $invoice->processing_status);
        self::assertSame('unknown', B2cReportingSubmission::query()->sole()->status);

        $this->travel(5)->minutes();
        $this->zatca->reported();
        $this->relay();
        self::assertSame(ProcessingStatus::Reported, $invoice->refresh()->processing_status);
    }

    // ---------------------------------------------------------------- 303, deadlines, reconciliation

    public function test_clearance_deactivated_reports_the_standard_invoice_instead(): void
    {
        Event::fake([ClearanceDeactivated::class]);
        $first = $this->zatcaManager()->b2b()->issue($this->payload('B2B-1', '10.00', $this->b2bExtras()), ['device' => $this->device->id])->toArray();
        $this->zatca->error(303, ['message' => 'Clearance is deactivated, please use the reporting API']);
        $this->relay();

        $invoice = $this->invoice($first['invoice_id']);
        self::assertSame(ClearanceStatus::ClearanceDeactivated, $invoice->clearance_status);
        self::assertSame(ProcessingStatus::Queued, $invoice->processing_status);
        Event::assertDispatched(ClearanceDeactivated::class);

        $this->zatca->reported();
        $this->relay();

        $invoice->refresh();
        self::assertSame(ProcessingStatus::Reported, $invoice->processing_status);
        self::assertSame(ReportingStatus::Reported, $invoice->reporting_status);
        $request = $this->zatca->request(count($this->zatca->history) - 1);
        self::assertStringEndsWith('/invoices/reporting/single', $request->getUri()->getPath());
        self::assertSame('0', $request->getHeaderLine('Clearance-Status'));
    }

    public function test_deadline_alerts_fire_once_per_threshold_then_breach(): void
    {
        Event::fake([InvoiceReportingDeadlineAtRisk::class, InvoiceReportingDeadlineBreached::class]);
        $first = $this->issueB2c('POS-1');
        $deadline = B2cReportingSubmission::query()->sole()->deadline_at;
        $monitor = app(DeadlineMonitor::class);

        self::assertSame(['at_risk' => 0, 'breached' => 0], $monitor->check($deadline->copy()->subHours(10)));
        self::assertSame(1, $monitor->check($deadline->copy()->subHours(3))['at_risk']);
        self::assertSame(0, $monitor->check($deadline->copy()->subHours(2))['at_risk'], 'not repeated');
        self::assertSame(1, $monitor->check($deadline->copy()->subMinutes(30))['at_risk'], '1 h threshold');
        self::assertSame(1, $monitor->check($deadline->copy()->addMinute())['breached']);
        self::assertSame(0, $monitor->check($deadline->copy()->addMinutes(5))['breached']);

        self::assertTrue((bool) B2cReportingSubmission::query()->sole()->deadline_breached);
        self::assertSame('reporting_deadline_breached', ZatcaError::query()->where('severity', 'CRITICAL')->value('code'));
        Event::assertDispatchedTimes(InvoiceReportingDeadlineAtRisk::class, 2);
        Event::assertDispatched(InvoiceReportingDeadlineBreached::class);
        self::assertSame($first['invoice_id'], $this->invoice($first['invoice_id'])->public_id);
    }

    public function test_reconciler_requeues_a_submission_whose_message_was_lost(): void
    {
        $first = $this->issueB2c('POS-1');
        OutboxMessage::query()->update(['processed_at' => now()]);    // simulate a lost message
        $this->travel(10)->minutes();

        self::assertSame(1, app(Reconciler::class)->reconcile()['submissions']);
        self::assertSame(0, app(Reconciler::class)->reconcile()['submissions'], 'not twice');

        $this->zatca->reported();
        $this->relay();
        self::assertSame(ProcessingStatus::Reported, $this->invoice($first['invoice_id'])->processing_status);
    }

    public function test_replay_completes_an_issuance_that_crashed_after_intake(): void
    {
        app(InvoiceIntakeService::class)->receive(Workflow::B2C, $this->payload('POS-1'), ['device' => $this->device->id]);

        $result = $this->issueB2c('POS-1');

        self::assertTrue($result['status']);
        self::assertSame(1, $result['counter']);
    }

    public function test_invoice_command_shows_and_retries(): void
    {
        $first = $this->issueB2c('POS-1');
        $this->artisan('zatca:invoice', ['action' => 'show', 'invoice' => $first['invoice_id']])->assertSuccessful();
        $this->zatca->reported();
        $this->artisan('zatca:invoice', ['action' => 'retry', 'invoice' => $first['invoice_id'], '--now' => true])
            ->expectsOutputToContain('reported')->assertSuccessful();
        $this->artisan('zatca:invoice', ['action' => 'reissue', 'invoice' => $first['invoice_id'], '--reason' => 'x'])->assertFailed();
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<string, mixed> */
    private function issueB2c(string $number, array $extra = []): array
    {
        return $this->zatcaManager()->b2c()->issue($this->payload($number, '10.00', $extra), ['device' => $this->device->id])->toArray();
    }

    /** @return array<string, mixed> */
    private function payload(string $number, string $price = '10.00', array $extra = []): array
    {
        return $extra + [
            'invoice_number' => $number,
            'lines' => [['name' => 'Coffee', 'quantity' => '2', 'unit_price' => $price, 'vat_category' => 'S', 'vat_rate' => '15']],
        ];
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

    private function invoice(string $publicId): Invoice
    {
        return Invoice::query()->where('public_id', $publicId)->firstOrFail();
    }

    private function ops(): \CodingTriplets\Zatca\Laravel\Invoices\InvoiceOperations
    {
        return $this->zatcaManager()->invoices();
    }

    private function zatcaManager(): ZatcaManager
    {
        return app(ZatcaManager::class);
    }

    private function signedXml(int $issuanceId): string
    {
        $a = InvoiceArtifact::query()->where('issuance_id', $issuanceId)->where('kind', ArtifactKind::SignedXml)->sole();

        return app(ArtifactStore::class)->get(new StoredArtifact($a->disk, $a->path, $a->sha256, (int) $a->bytes, (bool) $a->encrypted));
    }
}
