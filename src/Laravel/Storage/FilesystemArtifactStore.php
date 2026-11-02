<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Storage;

use CodingTriplets\Zatca\Core\Contracts\Security\CredentialVault;
use CodingTriplets\Zatca\Core\Contracts\Storage\ArtifactStore;
use CodingTriplets\Zatca\Core\Contracts\Storage\StoredArtifact;
use CodingTriplets\Zatca\Core\Domain\Exceptions\ZatcaException;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;

/**
 * Content-addressed, write-once artifact storage on a Laravel disk (local, s3, ...).
 *
 * Path = {root}/{namespace}/{sha[0..1]}/{sha}.{ext}. The SHA-256 is of the plaintext, so identical bytes always map
 * to the same object and an existing object is never rewritten. Reads verify the digest; a mismatch is an
 * integrity failure, never silently accepted. Optional at-rest encryption uses the credential vault.
 */
final class FilesystemArtifactStore implements ArtifactStore
{
    public function __construct(
        private readonly FilesystemFactory $filesystems,
        private readonly CredentialVault $vault,
        private readonly string $disk,
        private readonly string $root = 'zatca',
        private readonly bool $encrypt = false,
    ) {
    }

    public function putImmutable(string $namespace, string $contents, string $extension = 'xml'): StoredArtifact
    {
        $sha = hash('sha256', $contents);
        $namespace = trim((string) preg_replace('#[^A-Za-z0-9/_-]#', '_', $namespace), '/');
        $path = ltrim("{$this->root}/{$namespace}/" . substr($sha, 0, 2) . "/{$sha}.{$extension}", '/');
        $disk = $this->filesystems->disk($this->disk);

        if (! $disk->exists($path)) {
            $payload = $this->encrypt ? $this->vault->encrypt($contents) : $contents;
            if ($disk->put($path, $payload) === false) {
                throw new ZatcaException("Could not write artifact to disk [{$this->disk}].");
            }
        }

        $artifact = new StoredArtifact($this->disk, $path, $sha, strlen($contents), $this->encrypt);

        // Read-back check: the object at this address must hold exactly these bytes.
        if (! hash_equals($sha, hash('sha256', $this->read($artifact)))) {
            throw new ZatcaException("Artifact at [{$path}] does not match its content address.");
        }

        return $artifact;
    }

    public function get(StoredArtifact $artifact): string
    {
        $contents = $this->read($artifact);
        if (! hash_equals($artifact->sha256, hash('sha256', $contents))) {
            throw new ZatcaException("Integrity check failed for artifact [{$artifact->path}].");
        }

        return $contents;
    }

    public function exists(StoredArtifact $artifact): bool
    {
        return $this->filesystems->disk($artifact->disk)->exists($artifact->path);
    }

    private function read(StoredArtifact $artifact): string
    {
        $raw = $this->filesystems->disk($artifact->disk)->get($artifact->path);
        if ($raw === null) {
            throw new ZatcaException("Artifact [{$artifact->path}] is missing.");
        }

        return $artifact->encrypted ? $this->vault->decrypt($raw) : $raw;
    }
}
