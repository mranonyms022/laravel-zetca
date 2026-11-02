<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Crypto;

use CodingTriplets\Zatca\Core\Domain\Exceptions\CredentialException;

/**
 * An EGS signing key (ECDSA, secp256k1 by default) held in memory only.
 * Never serialized, never printed: __debugInfo hides it and __serialize throws.
 * There is deliberately no method to export the key except toPem(), which is used only to
 * encrypt it into the vault (ZATCA prohibits exporting the stamping key: Detailed Guidelines 6.7).
 */
final class EcPrivateKey
{
    private function __construct(
        #[\SensitiveParameter] private string $pem,
        private readonly string $curve,
        private readonly string $publicKeyDer,
    ) {
    }

    public static function generate(string $curve = 'secp256k1'): self
    {
        if (! in_array($curve, openssl_get_curve_names() ?: [], true)) {
            throw new CredentialException("The OpenSSL build does not support the curve [{$curve}].");
        }

        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => $curve]);
        if ($key === false || ! openssl_pkey_export($key, $pem)) {
            throw new CredentialException('Unable to generate an EC key pair: ' . self::opensslErrors());
        }

        return self::fromPem($pem);
    }

    public static function fromPem(#[\SensitiveParameter] string $pem): self
    {
        $key = openssl_pkey_get_private($pem);
        if ($key === false) {
            throw new CredentialException('Invalid EC private key.');
        }

        $details = openssl_pkey_get_details($key);
        if ($details === false || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC) {
            throw new CredentialException('The private key is not an EC key.');
        }

        return new self($pem, (string) $details['ec']['curve_name'], Der::pemToDer((string) $details['key']));
    }

    public function curve(): string
    {
        return $this->curve;
    }

    /** DER SubjectPublicKeyInfo. */
    public function publicKeyDer(): string
    {
        return $this->publicKeyDer;
    }

    public function publicKeyPem(): string
    {
        return Der::derToPem($this->publicKeyDer, 'PUBLIC KEY');
    }

    /** SHA-256 hex of the public key (identifies the key without revealing it). */
    public function fingerprint(): string
    {
        return hash('sha256', $this->publicKeyDer);
    }

    /** ECDSA-SHA256 signature, DER encoded. */
    public function sign(string $data): string
    {
        $key = openssl_pkey_get_private($this->pem);
        if ($key === false || ! openssl_sign($data, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new CredentialException('Signing failed: ' . self::opensslErrors());
        }

        return $signature;
    }

    /** For encryption into the vault only. */
    public function toPem(): string
    {
        return $this->pem;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['curve' => $this->curve, 'fingerprint' => $this->fingerprint(), 'key' => '[redacted]'];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('Private keys must never be serialized (e.g. into queued jobs or cache).');
    }

    private static function opensslErrors(): string
    {
        $errors = [];
        while (($e = openssl_error_string()) !== false) {
            $errors[] = $e;
        }

        return $errors === [] ? 'unknown OpenSSL error' : implode('; ', $errors);
    }
}
