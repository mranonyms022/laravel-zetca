<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Processing;

use CodingTriplets\Zatca\Core\Contracts\Events\EventDispatcher;
use CodingTriplets\Zatca\Core\Contracts\Persistence\TransactionManager;
use CodingTriplets\Zatca\Core\Domain\Enums\ErrorCategory;
use CodingTriplets\Zatca\Core\Domain\Enums\Severity;
use CodingTriplets\Zatca\Core\Events\InvoiceReportingDeadlineAtRisk;
use CodingTriplets\Zatca\Core\Events\InvoiceReportingDeadlineBreached;
use CodingTriplets\Zatca\Laravel\Database\Models\B2cReportingSubmission;
use CodingTriplets\Zatca\Laravel\Database\Models\Invoice;
use CodingTriplets\Zatca\Laravel\Database\Models\ProcessingLog;

/**
 * B2C reporting deadline watch (ARCHITECTURE 11): simplified documents must be reported within 24 hours.
 *  - `InvoiceReportingDeadlineAtRisk` once per threshold in `zatca.b2c.deadline_alert_hours` (default 4 h, 1 h);
 *  - after the deadline: `deadline_breached` is set, a CRITICAL error is recorded and
 *    `InvoiceReportingDeadlineBreached` fires. Reporting continues: a late report is better than none.
 * Idempotent: alerts already logged for an issuance are not repeated.
 */
final class DeadlineMonitor
{
    private const OPEN = ['pending', 'in_flight', 'unknown', 'failed'];

    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly ProcessingLogger $logger,
        private readonly ErrorRecorder $errors,
        private readonly EventDispatcher $events,
    ) {
    }

    /** @return array{at_risk: int, breached: int} */
    public function check(?\DateTimeInterface $now = null): array
    {
        $now = $now !== null ? \Illuminate\Support\Carbon::instance($now) : now();
        $thresholds = array_values(array_unique(array_map('intval', (array) config('zatca.b2c.deadline_alert_hours', [4, 1]))));
        rsort($thresholds);
        $horizon = $now->copy()->addHours(max([0, ...$thresholds]));
        $counts = ['at_risk' => 0, 'breached' => 0];

        B2cReportingSubmission::query()
            ->whereIn('status', self::OPEN)
            ->where('deadline_breached', false)
            ->where('deadline_at', '<=', $horizon)
            ->orderBy('deadline_at')
            ->chunkById(500, function ($submissions) use ($now, $thresholds, &$counts): void {
                foreach ($submissions as $submission) {
                    $invoice = Invoice::query()->withoutGlobalScopes()->find($submission->invoice_id);
                    if ($invoice === null || (int) $invoice->current_issuance_id !== (int) $submission->issuance_id) {
                        continue;   // superseded issuance
                    }
                    $deadline = $submission->deadline_at;
                    if ($deadline->lte($now)) {
                        $this->breached($submission, $invoice);
                        $counts['breached']++;

                        continue;
                    }
                    $secondsLeft = $now->diffInSeconds($deadline, true);
                    foreach ($thresholds as $hours) {
                        if ($secondsLeft <= $hours * 3600 && $this->alertOnce($invoice, (int) $submission->issuance_id, $hours, $deadline)) {
                            $counts['at_risk']++;
                            break;   // one alert per run: the smallest crossed threshold is reported next time
                        }
                    }
                }
            });

        return $counts;
    }

    private function alertOnce(Invoice $invoice, int $issuanceId, int $hours, \DateTimeInterface $deadline): bool
    {
        $event = "deadline_at_risk_{$hours}h";
        $exists = ProcessingLog::query()->where('invoice_id', $invoice->id)->where('issuance_id', $issuanceId)->where('event', $event)->exists();
        if ($exists) {
            return false;
        }

        $this->logger->log($invoice, 'reporting', $event, null, null, "Not reported yet; deadline in less than {$hours} h", ['deadline_at' => $deadline->format(DATE_ATOM)], $issuanceId);
        $this->events->dispatchAfterCommit(new InvoiceReportingDeadlineAtRisk((int) $invoice->id, (string) $invoice->public_id, $issuanceId, $deadline->format(DATE_ATOM), $hours));

        return true;
    }

    private function breached(B2cReportingSubmission $submission, Invoice $invoice): void
    {
        $this->transactions->run(function () use ($submission, $invoice): void {
            $updated = B2cReportingSubmission::query()->whereKey($submission->id)->where('deadline_breached', false)->update(['deadline_breached' => true, 'updated_at' => now()]);
            if ($updated !== 1) {
                return;
            }
            $deadline = $submission->deadline_at->format(DATE_ATOM);
            $this->logger->log($invoice, 'reporting', 'deadline_breached', null, null, "Reporting deadline {$deadline} passed", [], (int) $submission->issuance_id);
            $this->errors->record(ErrorCategory::ApiConnection, Severity::Critical, 'reporting_deadline_breached',
                "Simplified invoice {$invoice->invoice_number} was not reported within the deadline ({$deadline}).",
                'deadline.monitor', ['invoice' => $invoice, 'issuance_id' => (int) $submission->issuance_id]);
            $this->events->dispatchAfterCommit(new InvoiceReportingDeadlineBreached((int) $invoice->id, (string) $invoice->public_id, (int) $submission->issuance_id, $deadline));
        });
    }
}
