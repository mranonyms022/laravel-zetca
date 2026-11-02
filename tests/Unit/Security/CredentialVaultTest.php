<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Tests\Unit\Security;

use CodingTriplets\Zatca\Core\Domain\Exceptions\CredentialException;
use CodingTriplets\Zatca\Core\Security\EnvelopeCredentialVault;
use CodingTriplets\Zatca\Core\Security\LocalKeyManagementService;
use PHPUnit\Framework\TestCase;

final class CredentialVaultTest extends TestCase
{
    public function test_round_trip_without_plaintext_in_envelope(): void
    {
        $vault = self::vault(self::key());
        $envelope = $vault->encrypt('top-secret-value');

        self::assertStringNotContainsString('top-secret-value', $envelope);
        self::assertStringNotContainsString(base64_encode('top-secret-value'), $envelope);
        self::assertSame('top-secret-value', $vault->decrypt($envelope));
    }

    public function test_same_plaintext_encrypts_differently_each_time(): void
    {
        $vault = self::vault(self::key());

        self::assertNotSame($vault->encrypt('x'), $vault->encrypt('x'));
    }

    public function test_tampered_envelope_is_rejected(): void
    {
        $vault = self::vault(self::key());
        $data = json_decode($vault->encrypt('secret'), true);
        $raw = base64_decode($data['ct']);
        $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
        $data['ct'] = base64_encode($raw);

        $this->expectException(CredentialException::class);
        $vault->decrypt(json_encode($data));
    }

    public function test_wrong_master_key_cannot_decrypt(): void
    {
        $envelope = self::vault(self::key())->encrypt('secret');

        $this->expectException(CredentialException::class);
        self::vault(self::key())->decrypt($envelope);
    }

    public function test_rotation_keeps_old_envelopes_readable_and_reencrypts(): void
    {
        $old = self::key();
        $new = self::key();
        $envelope = self::vault($old)->encrypt('secret');

        $rotated = self::vault($new, $old);
        self::assertSame('secret', $rotated->decrypt($envelope));

        $reencrypted = $rotated->reencrypt($envelope);
        self::assertSame($rotated->currentKeyId(), $rotated->keyIdOf($reencrypted));
        self::assertSame('secret', self::vault($new)->decrypt($reencrypted));
    }

    public function test_master_key_must_be_32_bytes(): void
    {
        $this->expectException(CredentialException::class);
        new LocalKeyManagementService(['base64:' . base64_encode('short')]);
    }

    private static function key(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    private static function vault(string ...$keys): EnvelopeCredentialVault
    {
        return new EnvelopeCredentialVault(new LocalKeyManagementService($keys));
    }
}
