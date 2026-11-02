<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Security;

use CodingTriplets\Zatca\Core\Contracts\Security\KeyManagementService;
use CodingTriplets\Zatca\Core\Domain\Exceptions\CredentialException;

/**
 * Wraps data keys with local master keys (AES-256-GCM). Supports rotation: the first key encrypts,
 * all listed keys can decrypt. Replace with a KMS-backed implementation for HSM-grade protection.
 */
final class LocalKeyManagementService implements KeyManagementService
{
    /** @var array<string, string> keyId => 32-byte key */
    private array $keys = [];

    private string $currentKeyId;

    /** @param list<string> $masterKeys "base64:..." or raw 32-byte strings; first one is current */
    public function __construct(#[\SensitiveParameter] array $masterKeys)
    {
        foreach ($masterKeys as $raw) {
            $key = self::decodeKey($raw);
            $this->keys[self::keyId($key)] = $key;
        }

        if ($this->keys === []) {
            throw new CredentialException('No master encryption key configured (ZATCA_ENCRYPTION_KEY).');
        }

        $this->currentKeyId = (string) array_key_first($this->keys);
    }

    public function currentKeyId(): string
    {
        return $this->currentKeyId;
    }

    public function wrap(string $dataKey, string $keyId): string
    {
        return Aead::encrypt($dataKey, $this->key($keyId), 'zatca-kms:' . $keyId);
    }

    public function unwrap(string $wrappedDataKey, string $keyId): string
    {
        return Aead::decrypt($wrappedDataKey, $this->key($keyId), 'zatca-kms:' . $keyId);
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['currentKeyId' => $this->currentKeyId, 'keys' => '[redacted]'];
    }

    private function key(string $keyId): string
    {
        if (! isset($this->keys[$keyId])) {
            throw new CredentialException("Master key [{$keyId}] is not configured; was it rotated out?");
        }

        return $this->keys[$keyId];
    }

    private static function decodeKey(string $raw): string
    {
        $key = str_starts_with($raw, 'base64:') ? base64_decode(substr($raw, 7), true) : $raw;

        if (! is_string($key) || strlen($key) !== 32) {
            throw new CredentialException('Master encryption keys must be 32 bytes (use base64:<44 chars>).');
        }

        return $key;
    }

    private static function keyId(string $key): string
    {
        return 'local-' . substr(hash('sha256', 'zatca-key-id' . $key), 0, 16);
    }
}
