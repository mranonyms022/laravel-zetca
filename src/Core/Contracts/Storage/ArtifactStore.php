<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Contracts\Storage;

/**
 * Immutable, content-addressed storage for XML artifacts (private disks only).
 * The same bytes always map to the same path; existing objects are never overwritten.
 */
interface ArtifactStore
{
    /**
     * @param string $namespace e.g. "{tenant}/{company}/{device}/{yyyy}/{mm}"
     * @return StoredArtifact
     */
    public function putImmutable(string $namespace, string $contents, string $extension = 'xml'): StoredArtifact;

    /** Returns the exact bytes and verifies their SHA-256 against the expected digest. */
    public function get(StoredArtifact $artifact): string;

    public function exists(StoredArtifact $artifact): bool;
}
