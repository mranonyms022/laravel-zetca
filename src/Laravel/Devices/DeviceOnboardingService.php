<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Devices;

use CodingTriplets\Zatca\Core\Contracts\Device\ComplianceCheckRunner;
use CodingTriplets\Zatca\Core\Contracts\Events\EventDispatcher;
use CodingTriplets\Zatca\Core\Contracts\Locks\AdvisoryLock;
use CodingTriplets\Zatca\Core\Contracts\Persistence\TransactionManager;
use CodingTriplets\Zatca\Core\Domain\Enums\CertificateStatus;
use CodingTriplets\Zatca\Core\Domain\Enums\CertificateType;
use CodingTriplets\Zatca\Core\Domain\Enums\DeviceLifecycleStatus as S;
use CodingTriplets\Zatca\Core\Domain\Enums\Environment;
use CodingTriplets\Zatca\Core\Domain\Exceptions\DeviceStateException;
use CodingTriplets\Zatca\Core\Domain\Exceptions\FatooraApiException;
use CodingTriplets\Zatca\Core\Events\ComplianceCsidIssued;
use CodingTriplets\Zatca\Core\Events\DeviceKeyGenerated;
use CodingTriplets\Zatca\Core\Events\DeviceOnboarded;
use CodingTriplets\Zatca\Core\Events\DeviceOnboardingFailed;
use CodingTriplets\Zatca\Laravel\Audit\AuditLogger;
use CodingTriplets\Zatca\Laravel\Database\Models\Certificate;
use CodingTriplets\Zatca\Laravel\Database\Models\Device;
use CodingTriplets\Zatca\Laravel\Database\Models\DeviceKey;
use CodingTriplets\Zatca\Laravel\Database\Models\DeviceOnboarding;
use CodingTriplets\Zatca\Laravel\Fatoora\FatooraClientFactory;

/**
 * Device onboarding (ARCHITECTURE 10):
 *   REGISTERED → KEY_GENERATED → COMPLIANCE_CSID_ISSUED → COMPLIANCE_CHECKING → COMPLIANCE_PASSED → ACTIVE
 *
 * Rules:
 *  - No HTTP call runs inside a database transaction; each step commits its own result.
 *  - One onboarding at a time per device (advisory lock).
 *  - The OTP goes straight to ZATCA and is never stored, logged, audited or queued.
 *  - Each attempt uses a fresh key + CSR; earlier keys stay in history (append-only).
 */
final class DeviceOnboardingService
{
    public const STATUS_IN_PROGRESS = 'IN_PROGRESS';
    public const STATUS_AWAITING_CHECKS = 'AWAITING_COMPLIANCE_CHECKS';
    public const STATUS_COMPLETED = 'COMPLETED';
    public const STATUS_FAILED = 'FAILED';

    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly AdvisoryLock $lock,
        private readonly DeviceCredentialStore $credentials,
        private readonly FatooraClientFactory $fatoora,
        private readonly EventDispatcher $events,
        private readonly AuditLogger $audit,
        private readonly ?ComplianceCheckRunner $checks = null,
    ) {
    }

    /**
     * Runs onboarding as far as possible with one OTP: key + CSR, compliance CSID, compliance checks
     * (when available) and the production CSID.
     */
    public function onboard(Device $device, #[\SensitiveParameter] string $otp, ?int $actorId = null): DeviceOnboarding
    {
        return $this->locked($device, function () use ($device, $otp, $actorId): DeviceOnboarding {
            $device->refresh();
            $status = $device->lifecycle_status;

            if (! in_array($status, [S::Registered, S::KeyGenerated, S::ComplianceCsidIssued, S::OnboardingFailed], true)) {
                throw DeviceStateException::notAllowed((int) $device->id, 'start onboarding', $status->value,
                    $status->canIssue() ? 'Use certificate renewal instead.' : '');
            }

            $onboarding = DeviceOnboarding::create([
                'device_id' => $device->id,
                'environment' => $device->environment,
                'status' => self::STATUS_IN_PROGRESS,
                'current_step' => 'generate_key',
                'initiated_by' => $actorId,
            ]);
            $this->audit->record('device.onboarding.started', 'device', (int) $device->id, ['onboarding_id' => $onboarding->id], $actorId);

            // Step 1: key + CSR (committed before calling ZATCA).
            $key = $this->transactions->run(function () use ($device, $actorId): DeviceKey {
                $key = $this->credentials->createKey($device, $actorId);
                $device->update(['lifecycle_status' => S::KeyGenerated]);
                $this->events->dispatchAfterCommit(new DeviceKeyGenerated((int) $device->id, (int) $key->id, (string) $key->key_fingerprint));

                return $key;
            });

            // Step 2: compliance CSID (HTTP, outside any transaction).
            $onboarding->update(['current_step' => 'compliance_csid']);
            try {
                $response = $this->fatoora->for($device->environment)->requestComplianceCsid((string) $key->csr, $otp);
            } catch (FatooraApiException $e) {
                return $this->fail($device, $onboarding, 'compliance_csid', $e, $actorId);
            }

            $certificate = $this->transactions->run(function () use ($device, $key, $response, $onboarding): Certificate {
                Certificate::query()
                    ->where('device_id', $device->id)->where('environment', $device->environment)
                    ->where('type', CertificateType::Compliance)->where('status', CertificateStatus::Active)
                    ->get()->each(fn (Certificate $old) => $old->update(['status' => CertificateStatus::Superseded, 'superseded_at' => now()]));

                $certificate = $this->credentials->storeCertificate($device, $key, CertificateType::Compliance, $response, CertificateStatus::Active);
                $device->update(['lifecycle_status' => S::ComplianceCsidIssued]);
                $onboarding->update(['compliance_request_id' => $response->requestId, 'current_step' => 'compliance_checks']);
                $this->events->dispatchAfterCommit(new ComplianceCsidIssued((int) $device->id, (int) $certificate->id, $device->environment->value));

                return $certificate;
            });
            $this->audit->record('device.compliance_csid.issued', 'device', (int) $device->id, [
                'certificate_id' => $certificate->id, 'request_id' => $response->requestId,
            ], $actorId);

            return $this->continueAfterComplianceCsid($device, $onboarding, $actorId);
        });
    }

    /** Resumes an onboarding that stopped after the compliance CSID (e.g. once compliance checks are available). */
    public function resume(Device $device, ?int $actorId = null): DeviceOnboarding
    {
        return $this->locked($device, function () use ($device, $actorId): DeviceOnboarding {
            $device->refresh();
            if (! in_array($device->lifecycle_status, [S::ComplianceCsidIssued, S::ComplianceChecking, S::CompliancePassed], true)) {
                throw DeviceStateException::notAllowed((int) $device->id, 'resume onboarding', $device->lifecycle_status->value);
            }

            $onboarding = DeviceOnboarding::query()->where('device_id', $device->id)
                ->whereIn('status', [self::STATUS_IN_PROGRESS, self::STATUS_AWAITING_CHECKS])
                ->latest('id')->firstOrFail();

            return $this->continueAfterComplianceCsid($device, $onboarding, $actorId);
        });
    }

    private function continueAfterComplianceCsid(Device $device, DeviceOnboarding $onboarding, ?int $actorId): DeviceOnboarding
    {
        $compliance = $this->activeCertificate($device, CertificateType::Compliance);

        // Step 3: compliance checks.
        if ($device->lifecycle_status !== S::CompliancePassed) {
            if ($this->checks === null || $this->maySkipComplianceChecks($device->environment)) {
                if (! $this->maySkipComplianceChecks($device->environment)) {
                    $onboarding->update(['status' => self::STATUS_AWAITING_CHECKS, 'current_step' => 'compliance_checks']);

                    return $onboarding->refresh();
                }
                $onboarding->update(['compliance_checks' => ['skipped' => 'sandbox: zatca.devices.sandbox_skip_compliance_checks']]);
            } else {
                $device->update(['lifecycle_status' => S::ComplianceChecking]);
                $results = $this->checks->run(
                    $device->only(['id', 'device_uuid', 'invoice_type_flags', 'environment', 'name']),
                    $this->credentials->credentials($compliance),
                    $this->credentials->privateKey($compliance->deviceKey()->firstOrFail())->toPem(),
                );
                $onboarding->update(['compliance_checks' => $results]);

                $failed = array_keys(array_filter($results, static fn (array $r) => ! $r['passed']));
                if ($failed !== [] || $results === []) {
                    $device->update(['lifecycle_status' => S::ComplianceCsidIssued]);
                    $onboarding->update(['status' => self::STATUS_AWAITING_CHECKS, 'error' => 'Compliance checks failed: ' . implode(', ', $failed)]);

                    return $onboarding->refresh();
                }
            }
            $device->update(['lifecycle_status' => S::CompliancePassed]);
        }

        // Step 4: production CSID.
        $onboarding->update(['current_step' => 'production_csid']);
        try {
            $response = $this->fatoora->for($device->environment)->requestProductionCsid(
                (string) $compliance->request_id,
                $this->credentials->credentials($compliance),
            );
        } catch (FatooraApiException $e) {
            return $this->fail($device, $onboarding, 'production_csid', $e, $actorId);
        }

        $production = $this->transactions->run(function () use ($device, $compliance, $response, $onboarding): Certificate {
            $production = $this->credentials->storeCertificate(
                $device, $compliance->deviceKey()->firstOrFail(), CertificateType::Production, $response, CertificateStatus::Active,
            );
            $device->update([
                'lifecycle_status' => S::Active,
                'active_certificate_id' => $production->id,
                'certificate_expiry' => $production->valid_until,
            ]);
            $onboarding->update(['status' => self::STATUS_COMPLETED, 'current_step' => null, 'completed_at' => now(), 'error' => null]);
            $this->events->dispatchAfterCommit(new DeviceOnboarded(
                (int) $device->id, (int) $production->id, $device->environment->value, $production->valid_until->toDateTimeImmutable(),
            ));

            return $production;
        });

        $this->audit->record('device.onboarded', 'device', (int) $device->id, [
            'certificate_id' => $production->id, 'valid_until' => $production->valid_until,
        ], $actorId);

        return $onboarding->refresh();
    }

    private function fail(Device $device, DeviceOnboarding $onboarding, string $step, FatooraApiException $e, ?int $actorId): DeviceOnboarding
    {
        $this->transactions->run(function () use ($device, $onboarding, $step, $e): void {
            $onboarding->update(['status' => self::STATUS_FAILED, 'current_step' => $step, 'error' => $e->getMessage()]);
            // A failed production CSID keeps the valid compliance CSID so the step can be retried.
            if ($step !== 'production_csid') {
                $device->update(['lifecycle_status' => S::OnboardingFailed]);
            }
            $this->events->dispatchAfterCommit(new DeviceOnboardingFailed((int) $device->id, (int) $onboarding->id, $step, $e->getMessage()));
        });

        $this->audit->record('device.onboarding.failed', 'device', (int) $device->id, [
            'onboarding_id' => $onboarding->id, 'step' => $step, 'http_status' => $e->httpStatus, 'errors' => $e->errors,
        ], $actorId);

        return $onboarding->refresh();
    }

    private function activeCertificate(Device $device, CertificateType $type): Certificate
    {
        return Certificate::query()
            ->where('device_id', $device->id)->where('environment', $device->environment)
            ->where('type', $type)->where('status', CertificateStatus::Active)
            ->latest('version')->firstOrFail();
    }

    private function maySkipComplianceChecks(Environment $environment): bool
    {
        return $environment === Environment::Sandbox && (bool) config('zatca.devices.sandbox_skip_compliance_checks', false);
    }

    /** @template T @param callable(): T $callback @return T */
    private function locked(Device $device, callable $callback): mixed
    {
        return $this->lock->withLock("device:{$device->id}:credentials", 120, 5, $callback);
    }
}
