<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Storage;

use CodingTriplets\Zatca\Core\Contracts\Security\CredentialVault;
use CodingTriplets\Zatca\Core\Contracts\Storage\ArtifactStore;
use CodingTriplets\Zatca\Core\Contracts\Storage\StoredArtifact;
use CodingTriplets\Zatca\Core\Domain\Enums\ArtifactKind;
use CodingTriplets\Zatca\Core\Domain\Exceptions\ZatcaException;
use CodingTriplets\Zatca\Laravel\Database\Models\InvoiceArtifact;

/**
 * Writes and reads invoice XML artifacts (signed XML, ZATCA's cleared XML).
 *
 * `zatca.storage.driver`:
 *  - database (default): the bytes go into the artifact row (`content`), gzip-compressed, optionally encrypted with
 *    the credential vault, base64-encoded. One INSERT inside the issuance transaction, no file I/O, works on any
 *    number of servers, rolled back with the transaction, backed up with the database.
 *  - filesystem: the bytes go to the content-addressed ArtifactStore (a private disk), the row keeps the path.
 *
 * Reading follows the row, not the setting: rows with `content` are decoded from the database, older rows are read
 * from their disk. Either way the SHA-256 of the XML is verified on every read.
 */
final class InvoiceArtifacts
{
    public const DISK_DATABASE = 'database';

    public function __construct(
        private readonly ArtifactStore $files,
        private readonly CredentialVault $vault,
    ) {
    }

    public function store(int $invoiceId, ?int $issuanceId, ArtifactKind $kind, string $xml, string $namespace): InvoiceArtifact
    {
        $row = ['issuance_id' => $issuanceId, 'invoice_id' => $invoiceId, 'kind' => $kind, 'mime' => 'application/xml'];

        if (config('zatca.storage.driver', 'database') === 'filesystem') {
            $stored = $this->files->putImmutable($namespace, $xml);

            return InvoiceArtifact::query()->create($row + [
                'disk' => $stored->disk,
                'path' => $stored->path,
                'sha256' => $stored->sha256,
                'bytes' => $stored->bytes,
                'encrypted' => $stored->encrypted,
            ]);
        }

        [$content, $encoding, $encrypted] = $this->encode($xml);
        $sha = hash('sha256', $xml);

        return InvoiceArtifact::query()->create($row + [
            'disk' => self::DISK_DATABASE,
            'path' => "{$sha}.xml",
            'sha256' => $sha,
            'bytes' => strlen($xml),
            'encrypted' => $encrypted,
            'content' => $content,
            'encoding' => $encoding,
        ]);
    }

    /** The exact stored bytes; throws when they are missing or do not match the recorded SHA-256. */
    public function contents(InvoiceArtifact $artifact): string
    {
        $content = $artifact->getAttribute('content');
        if ($content === null || $content === '') {
            return $this->files->get(new StoredArtifact((string) $artifact->disk, (string) $artifact->path, (string) $artifact->sha256, (int) $artifact->bytes, (bool) $artifact->encrypted));
        }

        $xml = $this->decode((string) $content, (string) $artifact->getAttribute('encoding'));
        if (! hash_equals((string) $artifact->sha256, hash('sha256', $xml))) {
            throw new ZatcaException("Integrity check failed for artifact #{$artifact->id}.");
        }

        return $xml;
    }

    /** @return array{0: string, 1: string, 2: bool} content, encoding, encrypted */
    private function encode(string $xml): array
    {
        $steps = [];
        $bytes = $xml;
        if (function_exists('gzencode')) {
            $bytes = (string) gzencode($bytes, 6);
            $steps[] = 'gzip';
        }
        $encrypt = (bool) config('zatca.storage.encrypt_local', false);
        if ($encrypt) {
            $bytes = $this->vault->encrypt($bytes);
            $steps[] = 'vault';
        }
        $steps[] = 'base64';

        return [base64_encode($bytes), implode('+', $steps), $encrypt];
    }

    private function decode(string $content, string $encoding): string
    {
        $bytes = base64_decode($content, true);
        if ($bytes === false) {
            throw new ZatcaException('Stored artifact is not valid base64.');
        }
        $steps = $encoding === '' ? ['base64'] : explode('+', $encoding);
        foreach (array_reverse($steps) as $step) {
            $bytes = match ($step) {
                'base64' => $bytes,
                'vault' => $this->vault->decrypt($bytes),
                'gzip' => function_exists('gzdecode') ? gzdecode($bytes) : throw new ZatcaException('The zlib extension is required to read compressed artifacts.'),
                default => throw new ZatcaException("Unknown artifact encoding [{$step}]."),
            };
            if ($bytes === false) {
                throw new ZatcaException("Could not decode stored artifact ({$step}).");
            }
        }

        return $bytes;
    }
}
