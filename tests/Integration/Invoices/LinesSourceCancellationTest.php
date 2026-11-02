<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Tests\Integration\Invoices;

use CodingTriplets\Zatca\Core\Domain\Enums\DocumentType;
use CodingTriplets\Zatca\Core\Domain\Enums\ProcessingStatus;
use CodingTriplets\Zatca\Core\Events\InvoiceAccepted;
use CodingTriplets\Zatca\Core\Events\InvoiceCancelled;
use CodingTriplets\Zatca\Core\Fatoora\BasicAuthentication;
use CodingTriplets\Zatca\Laravel\Database\Models\Device;
use CodingTriplets\Zatca\Laravel\Database\Models\Invoice;
use CodingTriplets\Zatca\Laravel\Database\Models\InvoiceLine;
use CodingTriplets\Zatca\Laravel\Database\Models\InvoiceVatBreakdown;
use CodingTriplets\Zatca\Laravel\Database\Models\ZatcaError;
use CodingTriplets\Zatca\Laravel\Fatoora\FatooraClientFactory;
use CodingTriplets\Zatca\Laravel\Invoices\InvoiceOperations;
use CodingTriplets\Zatca\Laravel\Invoices\OperationNotAllowed;
use CodingTriplets\Zatca\Laravel\Processing\Outbox\OutboxRelay;
use CodingTriplets\Zatca\Laravel\ZatcaManager;
use CodingTriplets\Zatca\Tests\Integration\IntegrationTestCase;
use CodingTriplets\Zatca\Tests\Integration\Support\HostBill;
use CodingTriplets\Zatca\Tests\Integration\Support\OnboardsDevices;
use CodingTriplets\Zatca\Tests\Support\FakeZatca;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

/** M6.1: stored lines + VAT breakdown, link to the host record, cancel / discard / abandon, over-credit check. */
final class LinesSourceCancellationTest extends IntegrationTestCase
{
    use OnboardsDevices;

    private FakeZatca $zatca;

    private Device $device;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir() . '/zatca-m61-' . bin2hex(random_bytes(4));
        config([
            'filesystems.disks.zatca_test' => ['driver' => 'local', 'root' => $this->storage],
            'zatca.storage.disk' => 'zatca_test',
            'zatca.devices.sandbox_skip_compliance_checks' => true,
        ]);
        $this->zatca = new FakeZatca();
        $this->app->instance(FatooraClientFactory::class, new FatooraClientFactory(new BasicAuthentication(), $this->zatca->stack));
        $this->device = $this->activeDevice();
        Schema::dropIfExists('host_sales_bills');
        Schema::create('host_sales_bills', static function (Blueprint $t): void {
            $t->id();
            $t->string('number');
            $t->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('host_sales_bills');
        if (is_dir($this->storage)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->storage, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->storage);
        }
        parent::tearDown();
    }

    // ---------------------------------------------------------------- lines

    public function test_calculated_lines_and_vat_breakdown_are_stored_per_issuance(): void
    {
        $result = $this->zatca()->b2c()->issue([
            'invoice_number' => 'POS-L1',
            'lines' => [
                ['name' => 'Shirt', 'item_code' => 'SKU-1', 'quantity' => '3', 'unit_price' => '100', 'vat_category' => 'S', 'vat_rate' => '15',
                    'discounts' => [['percent' => '10', 'reason' => 'Member']]],
                ['name' => 'Medicine', 'sku' => 'MED-9', 'quantity' => '2', 'unit_price' => '25', 'vat_category' => 'Z', 'vat_rate' => '0',
                    'exemption_reason_code' => 'VATEX-SA-35', 'exemption_reason' => 'Medicines'],
            ],
        ], ['device' => $this->device->id])->toArray();

        $invoice = $this->invoice($result['invoice_id']);
        $lines = $invoice->lines()->get();
        self::assertCount(2, $lines);
        self::assertSame('SKU-1', $lines[0]->item_code);
        self::assertSame('30.00', $lines[0]->line_discount);
        self::assertSame('270.00', $lines[0]->net_amount);
        self::assertSame('40.50', $lines[0]->vat_amount);
        self::assertSame('310.50', $lines[0]->amount_incl_vat);
        self::assertSame('10.00', $lines[0]->discounts[0]['percent']);
        self::assertSame('MED-9', $lines[1]->item_code);
        self::assertSame('VATEX-SA-35', $lines[1]->exemption_reason_code);

        $vat = $invoice->vatBreakdown()->orderBy('vat_category')->get();
        self::assertSame(['S', 'Z'], $vat->pluck('vat_category')->all());
        self::assertSame('270.00', $vat[0]->taxable_amount);
        self::assertSame('40.50', $vat[0]->vat_amount);
        self::assertSame('360.50', (string) $invoice->total_incl_vat);
    }

    public function test_a_correction_stores_a_new_set_of_lines_and_keeps_the_old_one(): void
    {
        $first = $this->issue('POS-L2');
        $this->zatca->notReported();
        $this->relay();

        $this->ops()->correct($first['invoice_id'], $this->payload('POS-L2', '12.00'), 'Price', 1);
        $invoice = $this->invoice($first['invoice_id']);

        self::assertSame(2, InvoiceLine::query()->where('invoice_id', $invoice->id)->count(), 'one line per issuance');
        self::assertSame('24.00', $invoice->lines()->sole()->net_amount, 'current issuance only');
        self::assertSame(2, InvoiceVatBreakdown::query()->where('invoice_id', $invoice->id)->count());
    }

    public function test_lines_can_be_switched_off(): void
    {
        config(['zatca.database.store_lines' => false]);
        $result = $this->issue('POS-L3');

        self::assertSame(0, InvoiceLine::query()->count());
        self::assertSame(1, $this->invoice($result['invoice_id'])->issuance_count);
    }

    // ---------------------------------------------------------------- source link

    public function test_invoice_is_linked_to_the_host_model_and_events_carry_it(): void
    {
        Event::fake([InvoiceAccepted::class]);
        $bill = HostBill::query()->create(['number' => 'B-77']);

        $result = $this->zatca()->b2c()->issue($this->payload('POS-S1'), ['device' => $this->device->id, 'source' => $bill])->toArray();
        $this->zatca->reported();
        $this->relay();

        self::assertSame($result['invoice_id'], $bill->zatcaInvoice->public_id);
        self::assertCount(1, $bill->zatcaInvoices);
        self::assertSame($result['invoice_id'], Invoice::query()->forSource($bill)->sole()->public_id);
        self::assertTrue($this->invoice($result['invoice_id'])->source->is($bill));
        Event::assertDispatched(InvoiceAccepted::class, static fn (InvoiceAccepted $e) => $e->sourceType === HostBill::class && $e->sourceId === (string) $bill->id);

        $note = $this->ops()->creditNote($result['invoice_id'], $this->payload('CN-S1', '5.00'), 'Return')->toArray();
        self::assertTrue($this->invoice($note['invoice_id'])->source->is($bill), 'notes inherit the link');
        self::assertCount(2, $bill->refresh()->zatcaInvoices);
    }

    public function test_source_can_be_given_as_type_and_id(): void
    {
        $result = $this->zatca()->b2c()->issue($this->payload('POS-S2'), ['device' => $this->device->id, 'source' => ['type' => 'erp_bill', 'id' => 'ERP-9']])->toArray();

        $invoice = $this->invoice($result['invoice_id']);
        self::assertSame(['erp_bill', 'ERP-9'], [$invoice->source_type, $invoice->source_id]);
    }

    // ---------------------------------------------------------------- cancel (full credit note)

    public function test_cancel_issues_a_full_credit_note_and_marks_the_original(): void
    {
        Event::fake([InvoiceCancelled::class]);
        $original = $this->acceptedInvoice('POS-C1', ['allowances' => [['amount' => '2.00', 'reason' => 'Loyalty', 'vat_category' => 'S', 'vat_rate' => '15']]]);

        $result = $this->ops()->cancel($original, 'Order cancelled by the customer', 3);
        $note = $this->invoice($result->toArray()['invoice_id']);
        $original->refresh();

        self::assertSame(DocumentType::CreditNote, $note->document_type);
        self::assertSame('POS-C1-CN', $note->invoice_number);
        self::assertSame((string) $original->total_incl_vat, (string) $note->total_incl_vat, 'same lines, allowances and totals');
        self::assertSame((int) $note->id, (int) $original->cancellation_invoice_id);
        self::assertSame((string) $original->total_incl_vat, (string) $original->credited_amount);
        self::assertTrue($original->isFullyCredited());
        Event::assertDispatched(InvoiceCancelled::class, static fn (InvoiceCancelled $e) => $e->kind === 'credit_note' && $e->cancellationPublicId === $note->public_id && $e->actorId === 3);

        $this->expectException(OperationNotAllowed::class);
        $this->ops()->cancel($original, 'again');
    }

    public function test_credit_notes_cannot_exceed_the_invoice_total(): void
    {
        $original = $this->acceptedInvoice('POS-C2');   // 2 × 10 + 15 % = 23.00

        $this->ops()->creditNote($original, $this->payload('CN-1', '5.00'), 'Partial return');   // 11.50
        self::assertSame('11.50', $original->refresh()->creditableAmount());

        try {
            $this->ops()->creditNote($original, $this->payload('CN-2', '6.00'), 'Too much');     // 13.80 > 11.50
            self::fail('over-credit accepted');
        } catch (OperationNotAllowed $e) {
            self::assertStringContainsString('exceeds the amount that can still be credited (11.50 SAR)', $e->getMessage());
        }

        $this->ops()->debitNote($original, ['invoice_number' => 'DN-1', 'lines' => [['name' => 'Fee', 'quantity' => '1', 'unit_price' => '2.00', 'vat_category' => 'S', 'vat_rate' => '15']]], 'Extra fee');
        self::assertSame('13.80', $original->refresh()->creditableAmount(), 'debit notes raise the creditable amount');
        $this->ops()->creditNote($original, $this->payload('CN-3', '6.00'), 'Now it fits');
        self::assertTrue($original->refresh()->isFullyCredited());

        $this->expectException(OperationNotAllowed::class);
        $this->ops()->cancel($original, 'already credited');
    }

    // ---------------------------------------------------------------- discard / abandon

    public function test_discard_closes_an_unsigned_invoice_without_using_a_counter(): void
    {
        Event::fake([InvoiceCancelled::class]);
        $result = $this->zatca()->b2b()->submit($this->payload('B2B-D1') + $this->b2bExtras(), ['device' => $this->device->id])->toArray();

        $invoice = $this->ops()->discard($result['invoice_id'], 'Entered twice', 2);

        self::assertSame(ProcessingStatus::Cancelled, $invoice->processing_status);
        self::assertNotNull($invoice->closed_at);
        self::assertNull($invoice->current_issuance_id);
        $this->relay();   // the queued generation message finds nothing to do
        self::assertSame(ProcessingStatus::Cancelled, $invoice->refresh()->processing_status);
        self::assertSame(0, (int) \CodingTriplets\Zatca\Laravel\Database\Models\InvoiceCounter::query()->where('device_id', $this->device->id)->value('last_icv'));
        Event::assertDispatched(InvoiceCancelled::class, static fn (InvoiceCancelled $e) => $e->kind === 'discarded');

        $this->expectException(OperationNotAllowed::class);
        $this->ops()->correct($invoice, $this->payload('B2B-D1') + $this->b2bExtras(), 'too late');
    }

    public function test_signed_invoices_cannot_be_discarded(): void
    {
        $result = $this->issue('POS-D2');

        $this->expectException(OperationNotAllowed::class);
        $this->ops()->discard($result['invoice_id'], 'no');
    }

    public function test_abandon_closes_a_rejected_invoice_and_resolves_its_errors(): void
    {
        $result = $this->issue('POS-A1');
        $this->zatca->notReported();
        $this->relay();

        $invoice = $this->ops()->abandon($result['invoice_id'], 'Sale reversed in the POS', 4);

        self::assertSame(ProcessingStatus::Abandoned, $invoice->processing_status);
        self::assertSame(0, ZatcaError::query()->where('invoice_id', $invoice->id)->where('is_resolved', false)->count());
        self::assertSame(1, $invoice->issuance_count, 'the rejected document stays in the history and chain');

        foreach ([fn () => $this->ops()->retry($invoice), fn () => $this->ops()->reissueUnchanged($invoice, 'x'), fn () => $this->ops()->abandon($invoice, 'x')] as $op) {
            try {
                $op();
                self::fail('operation allowed on an abandoned invoice');
            } catch (OperationNotAllowed) {
            }
        }
    }

    public function test_abandoning_a_rejected_credit_note_frees_the_credited_amount(): void
    {
        $original = $this->acceptedInvoice('POS-A2');
        $note = $this->ops()->creditNote($original, $this->payload('CN-A2', '10.00'), 'Return')->toArray();
        self::assertTrue($original->refresh()->isFullyCredited());

        $this->zatca->notReported();
        $this->relay();
        $this->ops()->abandon($note['invoice_id'], 'Wrong note');

        self::assertSame('0.00', (string) $original->refresh()->credited_amount);
        self::assertSame('23.00', $original->creditableAmount());
    }

    // ---------------------------------------------------------------- helpers

    private function acceptedInvoice(string $number, array $extra = []): Invoice
    {
        $result = $this->zatca()->b2c()->issue($this->payload($number, '10.00', $extra), ['device' => $this->device->id])->toArray();
        $this->zatca->reported();
        $this->relay();
        $invoice = $this->invoice($result['invoice_id']);
        self::assertSame(ProcessingStatus::Reported, $invoice->processing_status);

        return $invoice;
    }

    /** @return array<string, mixed> */
    private function issue(string $number): array
    {
        return $this->zatca()->b2c()->issue($this->payload($number), ['device' => $this->device->id])->toArray();
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

    private function ops(): InvoiceOperations
    {
        return $this->zatca()->invoices();
    }

    private function zatca(): ZatcaManager
    {
        return app(ZatcaManager::class);
    }
}
