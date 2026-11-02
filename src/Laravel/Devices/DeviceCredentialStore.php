<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Devices;

use CodingTriplets\Zatca\Core\Contracts\Fatoora\CsidCredentials;
use CodingTriplets\Zatca\Core\Contracts\Security\CredentialVault;
use CodingTriplets\Zatca\Core\Crypto\EcPrivateKey;
use CodingTriplets\Zatca\Core\Crypto\X509Certificate;
use CodingTriplets\Zatca\Core\Device\CsrProfile;
use CodingTriplets\Zatca\Core\Domain\Enums\CertificateStatus;
use CodingTriplets\Zatca\Core\Domain\Enums\CertificateType;
use CodingTriplets\Zatca\Core\Domain\Exceptions\CredentialException;
use CodingTriplets\Zatca\Core\Domain\ValueObjects\EgsSerialNumber;
use CodingTriplets\Zatca\Core\Domain\ValueObjects\InvoiceTypeFlags;
use CodingTriplets\Zatca\Core\Domain\ValueObjects\VatNumber;
use CodingTriplets\Zatca\Core\Fatoora\CsidResponse;
use CodingTriplets\Zatca\Core\Device\CsrGenerator;
use CodingTriplets\Zatca\Laravel\Database\Models\Certificate;
use CodingTriplets\Zatca\Laravel\Database\Models\Device;
use CodingTriplets\Zatca\Laravel\Database\Models\DeviceKey;

/**
 * The only place that encrypts/decrypts device keys and CSID secrets.
 * Decrypted material is returned as in-memory objects that refuse serialization.
 */
final class DeviceCredentialStore
{
    public function __construct(
        private readonly CredentialVault $vault,
        private readonly CsrGenerator $csr,
    ) {
    }

    public function profileFor(Device $device): CsrProfile
    {
        $registration = $device->vatRegistration()->firstOrFail();
        $vat = VatNumber::fromString((string) $registration->vat_number);

        $unit = $vat->isGroup()
            ? (string) $registration->group_member_tin
            : (string) ($device->branch()->first()?->name ?? '');

        return new CsrProfile(
            $device->environment,
            (string) $device->name,
            EgsSerialNumber::fromString((string) $device->serial_number),
            $vat,
            $unit,
            (string) $registration->legal_name,
            InvoiceTypeFlags::fromString((string) $device->invoice_type_flags),
            (string) $device->location,
            (string) $device->industry,
        );
    }

    /** Generates a new key + CSR and stores them (key encrypted). Must run inside a transaction. */
    public function createKey(Device $device, ?int $actorId = null): DeviceKey
    {
        $key = EcPrivateKey::generate((string) config('zatca.csr.curve', 'secp256k1'));
        $csr = $this->csr->generate($this->profileFor($device), $key);

        return DeviceKey::create([
            'device_id' => $device->id,
            'environment' => $device->environment,
            'algorithm' => $key->curve(),
            'encrypted_private_key' => $this->vault->encrypt($key->toPem()),
            'public_key' => $key->publicKeyPem(),
            'csr' => $csr,
            'key_fingerprint' => $key->fingerprint(),
            'vault_key_id' => $this->vault->currentKeyId(),
            'generated_by' => $actorId,
        ]);
    }

    /** Stores a CSID returned by ZATCA (secret encrypted). Must run inside a transaction. */
    public function storeCertificate(Device $device, DeviceKey $key, CertificateType $type, CsidResponse $response, CertificateStatus $status): Certificate
    {
        $parsed = X509Certificate::fromBinarySecurityToken($response->binarySecurityToken);

        $version = 1 + (int) Certificate::query()
            ->where('device_id', $device->id)
            ->where('environment', $device->environment)
            ->where('type', $type)
            ->max('version');

        return Certificate::create([
            'device_key_id' => $key->id,
            'device_id' => $device->id,
            'environment' => $device->environment,
            'type' => $type,
            'version' => $version,
            'certificate' => $response->binarySecurityToken,
            'encrypted_secret' => $this->vault->encrypt($response->secret()),
            'vault_key_id' => $this->vault->currentKeyId(),
            'request_id' => $response->requestId,
            'serial' => mb_substr($parsed->serialNumber, 0, 128),
            'fingerprint' => $parsed->fingerprint,
            'valid_from' => $parsed->validFrom,
            'valid_until' => $parsed->validUntil,
            'status' => $status,
            'activated_at' => $status === CertificateStatus::Active ? now() : null,
        ]);
    }

    public function credentials(Certificate $certificate): CsidCredentials
    {
        return new CsidCredentials(
            (int) $certificate->id,
            (string) $certificate->certificate,
            $this->vault->decrypt((string) $certificate->encrypted_secret),
        );
    }

    public function privateKey(DeviceKey $key): EcPrivateKey
    {
        $private = EcPrivateKey::fromPem($this->vault->decrypt((string) $key->encrypted_private_key));

        if (! hash_equals((string) $key->key_fingerprint, $private->fingerprint())) {
            throw new CredentialException("Device key #{$key->id} does not match its recorded fingerprint.");
        }

        return $private;
    }
}
