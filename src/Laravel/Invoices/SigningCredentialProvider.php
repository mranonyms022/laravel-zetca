<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Invoices;

use CodingTriplets\Zatca\Core\Contracts\Fatoora\CsidCredentials;
use CodingTriplets\Zatca\Core\Domain\Enums\CertificateStatus;
use CodingTriplets\Zatca\Core\Domain\Enums\CertificateType;
use CodingTriplets\Zatca\Core\Domain\Enums\Environment;
use CodingTriplets\Zatca\Core\Invoice\Shared\Generation\SigningCredentials;
use CodingTriplets\Zatca\Core\Invoice\Shared\Signing\CertificateInfo;
use CodingTriplets\Zatca\Laravel\Database\Models\Certificate;
use CodingTriplets\Zatca\Laravel\Database\Models\Device;
use CodingTriplets\Zatca\Laravel\Devices\DeviceCredentialStore;

/**
 * Resolves the certificate + private key a device signs with, and the CSID it authenticates with.
 *
 * Decrypted keys may be kept in this process's memory for `zatca.performance.signing_key_memory_ttl` seconds
 * (long-running Octane/queue workers). They are never written to cache, logs or job payloads.
 *
 * Developer-portal sandbox: the portal returns a fixed sample production certificate that does not belong to the
 * device key (observed 2026-11-02). There, and only there, signing falls back to the device's compliance
 * certificate (ZATCA answers with a "certificate-issuer-name" warning); authentication still uses the production
 * CSID. In simulation and production a certificate that does not match the key makes the device unavailable.
 */
final class SigningCredentialProvider
{
    /** @var array<int, array{expires: float, signing: SigningCredentials, certificateId: int, fallback: bool}> */
    private array $memory = [];

    public function __construct(
        private readonly DeviceCredentialStore $store,
        private readonly int $ttlSeconds = 300,
    ) {
    }

    /** @return array{signing: SigningCredentials, certificateId: int, fallback: bool} */
    public function signing(Device $device): array
    {
        $production = $this->production($device);
        $cached = $this->memory[$production->id] ?? null;
        if ($cached !== null && $cached['expires'] > microtime(true)) {
            return $cached;
        }

        $key = $this->store->privateKey($production->deviceKey()->firstOrFail());
        $certificate = CertificateInfo::fromCertificate((string) $production->certificate);
        $signingCertificateId = (int) $production->id;
        $fallback = false;

        if (! hash_equals($certificate->publicKeyDer, $key->publicKeyDer())) {
            if ($device->environment !== Environment::Sandbox) {
                throw DeviceUnavailable::because((int) $device->id, 'the active production certificate does not belong to the device key');
            }
            $compliance = Certificate::query()
                ->where('device_id', $device->id)->where('environment', $device->environment)
                ->where('type', CertificateType::Compliance)->where('device_key_id', $production->device_key_id)
                ->latest('version')->first();
            if ($compliance === null) {
                throw DeviceUnavailable::because((int) $device->id, 'no certificate matches the device key');
            }
            $certificate = CertificateInfo::fromCertificate((string) $compliance->certificate);
            $signingCertificateId = (int) $compliance->id;
            $fallback = true;
        }

        $entry = [
            'expires' => microtime(true) + $this->ttlSeconds,
            'signing' => new SigningCredentials($certificate, $key),
            'certificateId' => $signingCertificateId,
            'fallback' => $fallback,
        ];
        if ($this->ttlSeconds > 0) {
            $this->memory[$production->id] = $entry;
        }

        return $entry;
    }

    /** Production CSID used to authenticate reporting / clearance calls. */
    public function submission(Device $device): CsidCredentials
    {
        return $this->store->credentials($this->production($device));
    }

    public function forget(): void
    {
        $this->memory = [];
    }

    private function production(Device $device): Certificate
    {
        if (! $device->lifecycle_status->canIssue()) {
            throw DeviceUnavailable::because((int) $device->id, "status is {$device->lifecycle_status->value}");
        }
        if ($device->active_certificate_id === null) {
            throw DeviceUnavailable::because((int) $device->id, 'no active production certificate');
        }

        $certificate = Certificate::query()->find($device->active_certificate_id);
        if ($certificate === null || $certificate->type !== CertificateType::Production || $certificate->status !== CertificateStatus::Active) {
            throw DeviceUnavailable::because((int) $device->id, 'no active production certificate');
        }
        if ($certificate->valid_until !== null && $certificate->valid_until->isPast()) {
            throw DeviceUnavailable::because((int) $device->id, 'the production certificate has expired');
        }

        return $certificate;
    }
}
