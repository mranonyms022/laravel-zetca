<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Contracts\Security;

/**
 * Master-key provider for the vault (local key, AWS KMS, HashiCorp Vault, Azure Key Vault...).
 * Wraps/unwraps per-record data keys; master key material may never leave the KMS.
 */
interface KeyManagementService
{
    public function currentKeyId(): string;

    /** @return string wrapped data key */
    public function wrap(string $dataKey, string $keyId): string;

    public function unwrap(string $wrappedDataKey, string $keyId): string;
}
