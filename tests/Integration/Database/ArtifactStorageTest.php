<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Tests\Integration\Database;

use CodingTriplets\Zatca\Core\Domain\Enums\ArtifactKind;
use CodingTriplets\Zatca\Core\Domain\Exceptions\ZatcaException;
use CodingTriplets\Zatca\Laravel\Database\Models\Invoice;
use CodingTriplets\Zatca\Laravel\Database\Models\InvoiceArtifact;
use CodingTriplets\Zatca\Laravel\Storage\InvoiceArtifacts;
use CodingTriplets\Zatca\Tests\Integration\IntegrationTestCase;
use CodingTriplets\Zatca\Tests\Integration\Support\OnboardsDevices;
use CodingTriplets\Zatca\Laravel\Fatoora\FatooraClientFactory;
use CodingTriplets\Zatca\Core\Fatoora\BasicAuthentication;
use CodingTriplets\Zatca\Laravel\ZatcaManager;
use CodingTriplets\Zatca\Tests\Support\FakeZatca;
use Illuminate\Support\Facades\Storage;

final class ArtifactStorageTest extends IntegrationTestCase
{
    use OnboardsDevices;

    private int $invoiceId;

    private FakeZatca $zatca;

    protected function setUp(): void
    {
        parent::setUp();
        config(['zatca.devices.sandbox_skip_compliance_checks' => true]);
        Storage::fake('zatca_test');
        config(['zatca.storage.disk' => 'zatca_test']);
        $this->zatca = new FakeZatca();
        $this->app->instance(FatooraClientFactory::class, new FatooraClientFactory(new BasicAuthentication(), $this->zatca->stack));
        $device = $this->activeDevice();
        $result = app(ZatcaManager::class)->b2c()->issue([
            'invoice_number' => 'POS-1',
            'lines' => [['name' => 'Tea', 'quantity' => '1', 'unit_price' => '10.00', 'vat_category' => 'S', 'vat_rate' => '15']],
        ], ['device' => $device->id])->toArray();
        $this->invoiceId = (int) Invoice::query()->where('public_id', $result['invoice_id'])->value('id');
    }

    public function test_signed_xml_is_stored_compressed_in_the_database_without_touching_the_disk(): void
    {
        $artifact = InvoiceArtifact::query()->where('invoice_id', $this->invoiceId)->where('kind', ArtifactKind::SignedXml)->sole();

        self::assertSame('database', $artifact->disk);
        self::assertSame('gzip+base64', $artifact->encoding);
        self::assertSame([], Storage::disk('zatca_test')->allFiles(), 'no file written');

        $xml = app(InvoiceArtifacts::class)->contents($artifact);
        self::assertStringContainsString('<cbc:ID>POS-1</cbc:ID>', $xml);
        self::assertSame((int) $artifact->bytes, strlen($xml));
        self::assertLessThan(strlen($xml) * 0.6, strlen((string) $artifact->content), 'compressed (even after base64)');
        self::assertArrayNotHasKey('content', $artifact->toArray(), 'never serialized with the row');
    }

    public function test_tampered_content_fails_the_integrity_check(): void
    {
        $artifact = InvoiceArtifact::query()->where('invoice_id', $this->invoiceId)->sole();
        $artifact->setAttribute('content', base64_encode((string) gzencode('<Invoice>forged</Invoice>')));

        $this->expectException(ZatcaException::class);
        app(InvoiceArtifacts::class)->contents($artifact);
    }

    public function test_encrypted_database_storage_round_trips(): void
    {
        config(['zatca.storage.encrypt_local' => true]);
        $stored = app(InvoiceArtifacts::class)->store($this->invoiceId, null, ArtifactKind::SignedXml, '<Invoice>secret</Invoice>', 'x');

        self::assertSame('gzip+vault+base64', $stored->encoding);
        self::assertTrue((bool) $stored->encrypted);
        $raw = (string) base64_decode((string) $stored->content);
        self::assertStringNotContainsString('secret', $raw);
        self::assertStringStartsWith('{"v":1', $raw, 'a vault envelope, not plain gzip');
        self::assertSame('<Invoice>secret</Invoice>', app(InvoiceArtifacts::class)->contents(InvoiceArtifact::query()->findOrFail($stored->id)));
    }

    public function test_filesystem_driver_rows_are_still_read_from_their_disk(): void
    {
        config(['zatca.storage.driver' => 'filesystem']);
        $stored = app(InvoiceArtifacts::class)->store($this->invoiceId, null, ArtifactKind::SignedXml, '<Invoice>on disk</Invoice>', 't/c1/d1');

        self::assertSame('zatca_test', $stored->disk);
        self::assertNull($stored->content);
        self::assertCount(1, Storage::disk('zatca_test')->allFiles());

        config(['zatca.storage.driver' => 'database']);   // switching the driver keeps old rows readable
        self::assertSame('<Invoice>on disk</Invoice>', app(InvoiceArtifacts::class)->contents(InvoiceArtifact::query()->findOrFail($stored->id)));
    }
}
