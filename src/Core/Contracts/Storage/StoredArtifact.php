<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Contracts\Storage;

final class StoredArtifact
{
    public function __construct(
        public readonly string $disk,
        public readonly string $path,
        public readonly string $sha256,
        public readonly int $bytes,
        public readonly bool $encrypted,
    ) {
    }
}
