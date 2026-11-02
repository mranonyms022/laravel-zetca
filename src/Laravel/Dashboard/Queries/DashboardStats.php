<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Dashboard\Queries;

use CodingTriplets\Zatca\Core\Domain\Enums\CertificateStatus;
use CodingTriplets\Zatca\Core\Domain\Enums\CertificateType;
use CodingTriplets\Zatca\Core\Domain\Enums\DeviceLifecycleStatus;
use CodingTriplets\Zatca\Core\Domain\Enums\Workflow;
use CodingTriplets\Zatca\Laravel\Database\Models\Certificate;
use CodingTriplets\Zatca\Laravel\Database\Models\Device;
use CodingTriplets\Zatca\Laravel\Database\Models\Invoice;
use CodingTriplets\Zatca\Laravel\Database\Models\ZatcaError;

/** Headline numbers for the dashboard home (grouped COUNTs on indexed columns). */
final class DashboardStats
{
    /**
     * @return array{
     *   b2b: array<string, int>, b2c: array<string, int>,
     *   unresolved_errors: int, devices: array<string, int>, expiring_certificates: int, deadline_at_risk: int
     * }
     */
    public function summary(?\DateTimeInterface $since = null): array
    {
        $result = ['b2b' => [], 'b2c' => []];
        foreach ([Workflow::B2B, Workflow::B2C] as $workflow) {
            $counts = Invoice::query()
                ->where('workflow', $workflow)
                ->when($since !== null, static fn ($q) => $q->where('created_at', '>=', $since))
                ->selectRaw('processing_status, COUNT(*) as aggregate')
                ->groupBy('processing_status')
                ->pluck('aggregate', 'processing_status')
                ->map(static fn ($n) => (int) $n);

            $groups = ['total' => (int) $counts->sum()];
            foreach (InvoiceListQuery::tabs($workflow) as $key => $tab) {
                if ($tab['statuses'] !== null) {
                    $groups[$key] = (int) collect($tab['statuses'])->sum(static fn ($s) => $counts[$s->value] ?? 0);
                }
            }
            $result[$workflow === Workflow::B2B ? 'b2b' : 'b2c'] = $groups;
        }

        $result['unresolved_errors'] = ZatcaError::query()->where('is_resolved', false)->count();
        $result['devices'] = Device::query()
            ->selectRaw('lifecycle_status, COUNT(*) as aggregate')->groupBy('lifecycle_status')
            ->pluck('aggregate', 'lifecycle_status')->map(static fn ($n) => (int) $n)->all();
        $result['expiring_certificates'] = Certificate::query()
            ->where('type', CertificateType::Production)->where('status', CertificateStatus::Active)
            ->where('valid_until', '<=', now()->addDays(30))->count();
        $result['deadline_at_risk'] = Invoice::query()
            ->where('workflow', Workflow::B2C)
            ->whereNotNull('reporting_deadline_at')->where('reporting_deadline_at', '<=', now()->addHours(4))
            ->whereNotIn('processing_status', ['REPORTED', 'COMPLETED', 'CANCELLED', 'ABANDONED'])
            ->count();

        return $result;
    }

    /** Active devices, for the home page and the list filters. */
    public function activeDeviceCount(): int
    {
        return Device::query()->where('lifecycle_status', DeviceLifecycleStatus::Active)->count();
    }
}
