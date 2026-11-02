<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Processing;

use CodingTriplets\Zatca\Core\Contracts\Storage\ArtifactStore;
use CodingTriplets\Zatca\Core\Contracts\Storage\StoredArtifact;
use CodingTriplets\Zatca\Core\Domain\Enums\ArtifactKind;
use CodingTriplets\Zatca\Core\Domain\Enums\ErrorCategory;
use CodingTriplets\Zatca\Core\Domain\Enums\Severity;
use CodingTriplets\Zatca\Core\Domain\ValueObjects\InvoiceHash;
use CodingTriplets\Zatca\Core\Invoice\Shared\Hashing\InvoiceHasher;
use CodingTriplets\Zatca\Laravel\Database\Models\ChainEntry;
use CodingTriplets\Zatca\Laravel\Database\Models\InvoiceArtifact;
use CodingTriplets\Zatca\Laravel\Database\Models\InvoiceCounter;
use CodingTriplets\Zatca\Laravel\Database\Models\InvoiceIssuance;

/**
 * Verifies a device's hash chain (ARCHITECTURE 13.2 layer 5):
 *  - ICVs are contiguous from 1, each PIH equals the previous entry's hash (first = ZATCA genesis value);
 *  - the counter head matches the last ledger entry;
 *  - with $deep, every stored signed XML is re-read (SHA-256 checked) and re-hashed: it must give the ledger hash.
 * Problems are recorded as CRITICAL `sequence` errors.
 */
final class ChainVerifier
{
    public function __construct(
        private readonly ArtifactStore $artifacts,
        private readonly ErrorRecorder $errors,
        private readonly InvoiceHasher $hasher = new InvoiceHasher(),
    ) {
    }

    /** @return array{device_id: int, environment: string, entries: int, problems: list<string>} */
    public function verify(int $deviceId, string $environment, bool $deep = false, bool $record = true): array
    {
        $problems = [];
        $expectedIcv = 1;
        $previous = InvoiceHash::GENESIS;
        $last = null;
        $count = 0;

        ChainEntry::query()->where('device_id', $deviceId)->where('environment', $environment)->orderBy('icv')
            ->chunk(1000, function ($entries) use (&$problems, &$expectedIcv, &$previous, &$last, &$count, $deep): void {
                foreach ($entries as $entry) {
                    $count++;
                    if ((int) $entry->icv !== $expectedIcv) {
                        $problems[] = "ICV gap: expected {$expectedIcv}, found {$entry->icv}.";
                        $expectedIcv = (int) $entry->icv;
                    }
                    if ((string) $entry->previous_invoice_hash !== $previous) {
                        $problems[] = "ICV {$entry->icv}: previous hash does not match the hash of ICV " . ($entry->icv - 1) . '.';
                    }
                    if ($deep) {
                        $problem = $this->checkArtifact($entry);
                        if ($problem !== null) {
                            $problems[] = "ICV {$entry->icv}: {$problem}";
                        }
                    }
                    $previous = (string) $entry->invoice_hash;
                    $last = $entry;
                    $expectedIcv++;
                }
            });

        $counter = InvoiceCounter::query()->where('device_id', $deviceId)->where('environment', $environment)->first();
        if ($counter !== null) {
            if ((int) $counter->last_icv !== (int) ($last?->icv ?? 0)) {
                $problems[] = "Counter head ICV {$counter->last_icv} does not match the last ledger entry " . ($last?->icv ?? 0) . '.';
            }
            if ($last !== null && (string) $counter->last_invoice_hash !== (string) $last->invoice_hash) {
                $problems[] = 'Counter head hash does not match the last ledger entry.';
            }
        }

        if ($record) {
            foreach ($problems as $problem) {
                $this->errors->record(ErrorCategory::Sequence, Severity::Critical, 'chain_integrity', "Device #{$deviceId} ({$environment}): {$problem}", 'chain.verify', ['device_id' => $deviceId]);
            }
        }

        return ['device_id' => $deviceId, 'environment' => $environment, 'entries' => $count, 'problems' => $problems];
    }

    private function checkArtifact(ChainEntry $entry): ?string
    {
        $issuance = InvoiceIssuance::query()->find($entry->issuance_id);
        $artifact = InvoiceArtifact::query()->where('issuance_id', $entry->issuance_id)->where('kind', ArtifactKind::SignedXml)->first();
        if ($issuance === null || $artifact === null) {
            return 'issuance or signed XML missing.';
        }
        if ((string) $issuance->invoice_hash !== (string) $entry->invoice_hash || (int) $issuance->icv !== (int) $entry->icv) {
            return 'issuance does not match the ledger.';
        }

        try {
            $xml = $this->artifacts->get(new StoredArtifact((string) $artifact->disk, (string) $artifact->path, (string) $artifact->sha256, (int) $artifact->bytes, (bool) $artifact->encrypted));
        } catch (\Throwable $e) {
            return 'signed XML unreadable: ' . $e->getMessage();
        }

        if (! hash_equals((string) $entry->invoice_hash, $this->hasher->base64($xml))) {
            return 'stored XML does not hash to the ledger hash.';
        }
        if (! str_contains($xml, '<cbc:UUID>' . $entry->icv . '</cbc:UUID>') || ! str_contains($xml, (string) $entry->previous_invoice_hash)) {
            return 'stored XML does not carry the ledger ICV / PIH.';
        }

        return null;
    }
}
