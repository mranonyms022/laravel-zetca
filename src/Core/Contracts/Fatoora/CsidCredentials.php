<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Contracts\Fatoora;

/**
 * Decrypted CSID material, held in memory only. __debugInfo and __serialize hide the secret.
 */
final class CsidCredentials
{
    public function __construct(
        public readonly int $certificateId,
        public readonly string $binarySecurityToken,
        #[\SensitiveParameter] private string $secret,
    ) {
    }

    public function secret(): string
    {
        return $this->secret;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['certificateId' => $this->certificateId, 'binarySecurityToken' => '[redacted]', 'secret' => '[redacted]'];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('CSID credentials must never be serialized (e.g. into queued jobs).');
    }
}
