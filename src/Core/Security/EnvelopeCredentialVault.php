<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Security;

use CodingTriplets\Zatca\Core\Contracts\Security\CredentialVault;
use CodingTriplets\Zatca\Core\Contracts\Security\KeyManagementService;
use CodingTriplets\Zatca\Core\Domain\Exceptions\CredentialException;

/**
 * Envelope encryption: each secret gets a fresh random data key (AES-256-GCM); the data key is
 * wrapped by the KMS master key. Envelope = JSON {v, kid, wk, ct}, safe to store in the database.
 */
final class EnvelopeCredentialVault implements CredentialVault
{
    public function __construct(private readonly KeyManagementService $kms)
    {
    }

    public function encrypt(#[\SensitiveParameter] string $plaintext): string
    {
        $keyId = $this->kms->currentKeyId();
        $dataKey = random_bytes(32);

        $envelope = json_encode([
            'v' => 1,
            'kid' => $keyId,
            'wk' => $this->kms->wrap($dataKey, $keyId),
            'ct' => Aead::encrypt($plaintext, $dataKey, 'zatca-vault:v1:' . $keyId),
        ], JSON_THROW_ON_ERROR);

        return $envelope;
    }

    public function decrypt(string $envelope): string
    {
        $data = $this->parse($envelope);
        $dataKey = $this->kms->unwrap($data['wk'], $data['kid']);

        return Aead::decrypt($data['ct'], $dataKey, 'zatca-vault:v1:' . $data['kid']);
    }

    public function currentKeyId(): string
    {
        return $this->kms->currentKeyId();
    }

    public function reencrypt(string $envelope): string
    {
        if ($this->keyIdOf($envelope) === $this->kms->currentKeyId()) {
            return $envelope;
        }

        return $this->encrypt($this->decrypt($envelope));
    }

    public function keyIdOf(string $envelope): string
    {
        return $this->parse($envelope)['kid'];
    }

    /** @return array{v:int, kid:string, wk:string, ct:string} */
    private function parse(string $envelope): array
    {
        try {
            $data = json_decode($envelope, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new CredentialException('Credential envelope is not valid.');
        }

        if (! is_array($data) || ($data['v'] ?? null) !== 1 || ! is_string($data['kid'] ?? null)
            || ! is_string($data['wk'] ?? null) || ! is_string($data['ct'] ?? null)) {
            throw new CredentialException('Credential envelope has an unsupported format.');
        }

        return $data;
    }
}
