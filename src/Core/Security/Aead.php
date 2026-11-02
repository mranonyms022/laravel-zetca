<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Security;

use CodingTriplets\Zatca\Core\Domain\Exceptions\CredentialException;

/** AES-256-GCM helper. Output: base64(iv(12) || tag(16) || ciphertext). */
final class Aead
{
    private const CIPHER = 'aes-256-gcm';

    public static function encrypt(#[\SensitiveParameter] string $plaintext, #[\SensitiveParameter] string $key, string $aad = ''): string
    {
        $iv = random_bytes(12);
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, $aad, 16);

        if ($ciphertext === false) {
            throw new CredentialException('Encryption failed.');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    public static function decrypt(string $payload, #[\SensitiveParameter] string $key, string $aad = ''): string
    {
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 29) {
            throw new CredentialException('Encrypted payload is malformed.');
        }

        $plaintext = openssl_decrypt(substr($raw, 28), self::CIPHER, $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), $aad);

        if ($plaintext === false) {
            throw new CredentialException('Decryption failed: wrong key or tampered data.');
        }

        return $plaintext;
    }
}
