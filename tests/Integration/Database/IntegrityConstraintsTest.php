<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Tests\Integration\Database;

use CodingTriplets\Zatca\Core\Domain\Enums\Environment;
use CodingTriplets\Zatca\Core\Domain\Enums\IssuanceStatus;
use CodingTriplets\Zatca\Laravel\Database\Models\AppendOnlyViolation;
use CodingTriplets\Zatca\Laravel\Database\Models\ChainEntry;
use CodingTriplets\Zatca\Laravel\Database\Models\InvoiceVersion;
use CodingTriplets\Zatca\Tests\Integration\IntegrationTestCase;
use CodingTriplets\Zatca\Tests\Integration\Support\Fixtures;
use Illuminate\Database\QueryException;

/**
 * Storage-level backstops from ARCHITECTURE 13.2 (layer 4) and rule R2/R14.
 */
final class IntegrityConstraintsTest extends IntegrationTestCase
{
    public function test_duplicate_icv_on_the_same_device_is_rejected_by_the_database(): void
    {
        $device = Fixtures::device();
        $cert = Fixtures::certificate($device);
        Fixtures::issuance(Fixtures::invoice($device, externalId: 'A'), $cert, icv: 1);

        $this->expectException(QueryException::class);
        Fixtures::issuance(Fixtures::invoice($device, externalId: 'B'), $cert, icv: 1);
    }

    public function test_chain_ledger_rejects_duplicate_icv_and_forks(): void
    {
        $device = Fixtures::device();
        $cert = Fixtures::certificate($device);
        $first = Fixtures::issuance(Fixtures::invoice($device, externalId: 'A'), $cert, icv: 1);
        $second = Fixtures::issuance(Fixtures::invoice($device, externalId: 'B'), $cert, icv: 2);

        ChainEntry::create($this->entry($device->id, 1, 'H1', 'GENESIS', $first->id));

        // Same predecessor hash used twice = a fork in the chain.
        try {
            ChainEntry::create($this->entry($device->id, 2, 'H2', 'GENESIS', $second->id));
            self::fail('Chain fork was accepted.');
        } catch (QueryException) {
            self::assertSame(1, ChainEntry::count());
        }

        ChainEntry::create($this->entry($device->id, 2, 'H2', 'H1', $second->id));
        self::assertSame(2, ChainEntry::count());
    }

    public function test_same_icv_is_allowed_on_different_devices_and_environments(): void
    {
        $a = Fixtures::device();
        $b = Fixtures::device();

        Fixtures::issuance(Fixtures::invoice($a, externalId: 'A'), Fixtures::certificate($a), icv: 1);
        Fixtures::issuance(Fixtures::invoice($b, externalId: 'B'), Fixtures::certificate($b), icv: 1);

        self::assertDatabaseCount('zatca_invoice_issuances', 2);
    }

    public function test_intake_is_idempotent_per_company_and_external_id(): void
    {
        $device = Fixtures::device();
        Fixtures::invoice($device, externalId: 'SAME');

        $this->expectException(QueryException::class);
        Fixtures::invoice($device, externalId: 'SAME');
    }

    public function test_payload_versions_are_append_only(): void
    {
        $invoice = Fixtures::invoice(Fixtures::device());
        $version = InvoiceVersion::query()->findOrFail($invoice->current_version_id);

        try {
            $version->update(['payload' => ['tampered' => true]]);
            self::fail('Original payload was overwritten.');
        } catch (AppendOnlyViolation) {
            self::assertSame(['id' => 'EXT-1'], $version->fresh()?->payload);
        }

        $this->expectException(AppendOnlyViolation::class);
        $version->delete();
    }

    public function test_issuance_identity_is_frozen_but_status_may_change(): void
    {
        $device = Fixtures::device();
        $issuance = Fixtures::issuance(Fixtures::invoice($device), Fixtures::certificate($device), icv: 1);

        $issuance->update(['status' => IssuanceStatus::Rejected]);
        self::assertSame(IssuanceStatus::Rejected, $issuance->fresh()?->status);

        $this->expectException(AppendOnlyViolation::class);
        $issuance->update(['icv' => 99]);
    }

    /** @return array<string, mixed> */
    private function entry(int $deviceId, int $icv, string $hash, string $pih, int $issuanceId): array
    {
        return [
            'device_id' => $deviceId, 'environment' => Environment::Sandbox, 'icv' => $icv,
            'invoice_hash' => $hash, 'previous_invoice_hash' => $pih, 'issuance_id' => $issuanceId,
            'committed_at' => now(),
        ];
    }
}
