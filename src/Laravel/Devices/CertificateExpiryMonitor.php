<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Devices;

use CodingTriplets\Zatca\Core\Contracts\Events\EventDispatcher;
use CodingTriplets\Zatca\Core\Contracts\Persistence\TransactionManager;
use CodingTriplets\Zatca\Core\Domain\Enums\CertificateStatus;
use CodingTriplets\Zatca\Core\Domain\Enums\DeviceLifecycleStatus as S;
use CodingTriplets\Zatca\Core\Events\CertificateExpired;
use CodingTriplets\Zatca\Core\Events\CertificateExpiring;
use CodingTriplets\Zatca\Laravel\Database\Models\Certificate;
use CodingTriplets\Zatca\Laravel\Database\Models\Device;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Daily check: marks devices RENEWAL_DUE / EXPIRED and raises warnings at the configured thresholds
 * (default 30, 14, 7, 1 days). Each threshold fires once per certificate.
 */
final class CertificateExpiryMonitor
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly EventDispatcher $events,
        private readonly Cache $cache,
    ) {
    }

    /** @return array{checked: int, renewal_due: int, expired: int, warnings: int} */
    public function check(?\DateTimeInterface $now = null): array
    {
        $now = $now !== null ? \Carbon\CarbonImmutable::instance($now) : \Carbon\CarbonImmutable::now();
        $thresholds = array_map('intval', (array) config('zatca.devices.renewal_warning_days', [30, 14, 7, 1]));
        rsort($thresholds);
        $window = $thresholds[0] ?? 30;
        $stats = ['checked' => 0, 'renewal_due' => 0, 'expired' => 0, 'warnings' => 0];

        Device::query()->withoutGlobalScopes()
            ->whereIn('lifecycle_status', [S::Active, S::RenewalDue, S::RenewalFailed, S::Renewing])
            ->whereNotNull('certificate_expiry')
            ->where('certificate_expiry', '<=', $now->addDays($window))
            ->orderBy('id')
            ->chunkById(500, function ($devices) use ($now, $thresholds, &$stats): void {
                foreach ($devices as $device) {
                    $stats['checked']++;
                    $expiry = \Carbon\CarbonImmutable::instance($device->certificate_expiry);

                    if ($expiry <= $now) {
                        $this->expire($device);
                        $stats['expired']++;
                        continue;
                    }

                    if ($device->lifecycle_status === S::Active) {
                        $device->update(['lifecycle_status' => S::RenewalDue]);
                        $stats['renewal_due']++;
                    }

                    // Nearest threshold at or above the days left (e.g. 10 days left → the 14-day warning).
                    $daysLeft = (int) ceil(abs($now->diffInSeconds($expiry)) / 86400);
                    $applicable = array_values(array_filter($thresholds, static fn (int $t) => $daysLeft <= $t));
                    $threshold = $applicable === [] ? null : min($applicable);

                    if ($threshold !== null && $this->cache->add("zatca:expiry-warning:{$device->active_certificate_id}:{$threshold}", 1, 86400 * 400)) {
                        $this->events->dispatchAfterCommit(new CertificateExpiring(
                            (int) $device->id, (int) $device->active_certificate_id, $daysLeft, $expiry->toDateTimeImmutable(),
                        ));
                        $stats['warnings']++;
                    }
                }
            });

        return $stats;
    }

    private function expire(Device $device): void
    {
        $this->transactions->run(function () use ($device): void {
            Certificate::query()->whereKey($device->active_certificate_id)
                ->where('status', CertificateStatus::Active)
                ->get()->each(fn (Certificate $c) => $c->update(['status' => CertificateStatus::Expired]));
            $device->update(['lifecycle_status' => S::Expired]);
            $this->events->dispatchAfterCommit(new CertificateExpired((int) $device->id, (int) $device->active_certificate_id));
        });
    }
}
