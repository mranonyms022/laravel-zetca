<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Contracts\Security;

/**
 * Encrypts private keys and CSID secrets at rest (envelope encryption).
 * Decrypted values must only live in memory for the duration of use: never cached, logged or queued.
 */
interface CredentialVault
{
    /** @return string opaque ciphertext envelope (safe to store) */
    public function encrypt(string $plaintext): string;

    public function decrypt(string $envelope): string;

    /** Identifier of the master key used for new envelopes (supports rotation). */
    public function currentKeyId(): string;

    /** Re-encrypts an envelope under the current master key. */
    public function reencrypt(string $envelope): string;
}
