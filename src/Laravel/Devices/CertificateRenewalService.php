<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Devices;

use CodingTriplets\Zatca\Core\Contracts\Events\EventDispatcher;
use CodingTriplets\Zatca\Core\Contracts\Locks\AdvisoryLock;
use CodingTriplets\Zatca\Core\Contracts\Persistence\TransactionManager;
use CodingTriplets\Zatca\Core\Domain\Enums\CertificateStatus;
use CodingTriplets\Zatca\Core\Domain\Enums\CertificateType;
use CodingTriplets\Zatca\Core\Domain\Enums\DeviceLifecycleStatus as S;
use CodingTriplets\Zatca\Core\Domain\Exceptions\DeviceStateException;
use CodingTriplets\Zatca\Core\Domain\Exceptions\FatooraApiException;
use CodingTriplets\Zatca\Core\Events\CertificateRenewalFailed;
use CodingTriplets\Zatca\Core\Events\CertificateRenewed;
use CodingTriplets\Zatca\Laravel\Audit\AuditLogger;
use CodingTriplets\Zatca\Laravel\Database\Models\Certificate;
use CodingTriplets\Zatca\Laravel\Database\Models\Device;
use CodingTriplets\Zatca\Laravel\Database\Models\DeviceRenewal;
use CodingTriplets\Zatca\Laravel\Fatoora\FatooraClientFactory;

/**
 * Production CSID renewal (PATCH /production/csids with a new CSR and OTP, authenticated with the
 * current production CSID). The device keeps issuing with the old certificate until the new one
 * is stored; the switch is a single transaction. Old certificates are kept (superseded). ⚠ Q-Z6
 */
final class CertificateRenewalService
{
    public const STATUS_IN_PROGRESS = 'IN_PROGRESS';
    public const STATUS_COMPLETED = 'COMPLETED';
    public const STATUS_FAILED = 'FAILED';

    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly AdvisoryLock $lock,
        private readonly DeviceCredentialStore $credentials,
        private readonly FatooraClientFactory $fatoora,
        private readonly EventDispatcher $events,
        private readonly AuditLogger $audit,
    ) {
    }

    public function renew(Device $device, #[\SensitiveParameter] string $otp, ?int $actorId = null): DeviceRenewal
    {
        return $this->lock->withLock("device:{$device->id}:credentials", 120, 5, function () use ($device, $otp, $actorId): DeviceRenewal {
            $device->refresh();
            $previousStatus = $device->lifecycle_status;

            if (! in_array($previousStatus, [S::Active, S::RenewalDue, S::RenewalFailed, S::Expired], true)) {
                throw DeviceStateException::notAllowed((int) $device->id, 'renew its certificate', $previousStatus->value);
            }

            $current = Certificate::query()->whereKey($device->active_certificate_id)
                ->where('type', CertificateType::Production)->first();
            if ($current === null) {
                throw DeviceStateException::notAllowed((int) $device->id, 'renew its certificate', $previousStatus->value, 'No active production certificate.');
            }

            $renewal = DeviceRenewal::create([
                'device_id' => $device->id,
                'environment' => $device->environment,
                'previous_certificate_id' => $current->id,
                'status' => self::STATUS_IN_PROGRESS,
                'initiated_by' => $actorId,
            ]);

            $key = $this->transactions->run(function () use ($device, $actorId) {
                $key = $this->credentials->createKey($device, $actorId);
                $device->update(['lifecycle_status' => S::Renewing]);

                return $key;
            });

            try {
                $response = $this->fatoora->for($device->environment)->renewProductionCsid(
                    (string) $key->csr, $otp, $this->credentials->credentials($current),
                );
            } catch (FatooraApiException $e) {
                return $this->fail($device, $renewal, $previousStatus, $e, $actorId);
            }

            $new = $this->transactions->run(function () use ($device, $key, $response, $current, $renewal): Certificate {
                $new = $this->credentials->storeCertificate($device, $key, CertificateType::Production, $response, CertificateStatus::Active);
                $current->update(['status' => CertificateStatus::Superseded, 'superseded_at' => now()]);
                $device->update([
                    'lifecycle_status' => S::Active,
                    'active_certificate_id' => $new->id,
                    'certificate_expiry' => $new->valid_until,
                ]);
                $renewal->update(['status' => self::STATUS_COMPLETED, 'new_certificate_id' => $new->id, 'completed_at' => now(), 'error' => null]);
                $this->events->dispatchAfterCommit(new CertificateRenewed(
                    (int) $device->id, (int) $current->id, (int) $new->id, $new->valid_until->toDateTimeImmutable(),
                ));

                return $new;
            });

            $this->audit->record('device.certificate.renewed', 'device', (int) $device->id, [
                'previous_certificate_id' => $current->id, 'new_certificate_id' => $new->id, 'valid_until' => $new->valid_until,
            ], $actorId);

            return $renewal->refresh();
        });
    }

    private function fail(Device $device, DeviceRenewal $renewal, S $previousStatus, FatooraApiException $e, ?int $actorId): DeviceRenewal
    {
        $this->transactions->run(function () use ($device, $renewal, $previousStatus, $e): void {
            $renewal->update(['status' => self::STATUS_FAILED, 'error' => $e->getMessage()]);
            // The old certificate is untouched: an unexpired device keeps issuing.
            $device->update(['lifecycle_status' => $previousStatus === S::Expired ? S::Expired : S::RenewalFailed]);
            $this->events->dispatchAfterCommit(new CertificateRenewalFailed((int) $device->id, (int) $renewal->id, $e->getMessage()));
        });

        $this->audit->record('device.certificate.renewal_failed', 'device', (int) $device->id, [
            'renewal_id' => $renewal->id, 'http_status' => $e->httpStatus, 'errors' => $e->errors,
        ], $actorId);

        return $renewal->refresh();
    }
}
