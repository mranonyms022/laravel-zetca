<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Processing\Submission;

use CodingTriplets\Zatca\Core\Domain\Enums\Workflow;
use Illuminate\Support\Carbon;

/**
 * Backoff for failed submissions (ARCHITECTURE 15.3). Two kinds of failure are treated differently:
 *
 *  - ZATCA unavailable (connection failure, timeout, 5xx, 429) — {@see outageDelay()}: these do NOT use up
 *    `max_attempts`. The document is retried until its window closes: B2C → the reporting deadline, B2B →
 *    `zatca.retry.outage.b2b_window_hours` after the first attempt. The gap between tries grows along the backoff
 *    schedule but never beyond `zatca.retry.outage.max_delay_seconds`, so the backlog goes out soon after ZATCA is back.
 *    (While the circuit breaker is open nothing is sent at all; see CircuitBreaker.)
 *
 *  - anything else that is worth retrying (unknown outcome) — {@see delay()}: limited by `max_attempts`; B2C
 *    attempts are compressed so they stay before `deadline - b2c_deadline_margin_hours`.
 *
 * All delays get full jitter (0.5x–1.0x) to avoid thundering herds.
 */
final class RetryPolicy
{
    /** @return int|null seconds until the next attempt, null = give up */
    public function delay(Workflow $workflow, int $attemptsSoFar, ?\DateTimeInterface $deadline = null, ?\DateTimeInterface $now = null): ?int
    {
        $config = (array) config('zatca.retry.' . $workflow->value, []);
        $max = (int) ($config['max_attempts'] ?? 10);
        if ($attemptsSoFar >= $max) {
            return null;
        }

        $delay = $this->jittered($this->scheduled($workflow, $attemptsSoFar));

        if ($workflow === Workflow::B2C && $deadline !== null) {
            $now ??= Carbon::now();
            $latest = $deadline->getTimestamp() - (int) config('zatca.retry.b2c_deadline_margin_hours', 4) * 3600;
            $room = $latest - $now->getTimestamp();
            if ($room > 0) {
                $delay = min($delay, max(1, (int) floor($room / max(1, $max - $attemptsSoFar))));
            } else {
                // Inside the safety margin (or past the deadline): keep trying at the shortest interval.
                $delay = min($delay, max(1, $this->scheduled($workflow, 1)));
            }
        }

        return $delay;
    }

    /**
     * ZATCA unavailable: retry until the window closes, without an attempt limit.
     *
     * @param int $tries how many times this document has been tried so far (only shapes the backoff)
     * @return int|null seconds until the next try, null = the window is closed
     */
    public function outageDelay(Workflow $workflow, int $tries, ?\DateTimeInterface $deadline, ?\DateTimeInterface $firstAttemptAt, ?\DateTimeInterface $now = null): ?int
    {
        $now = ($now ?? Carbon::now())->getTimestamp();
        $closesAt = $this->windowClosesAt($workflow, $deadline, $firstAttemptAt, $now);
        $room = $closesAt - $now;
        if ($room <= 0) {
            return null;
        }

        $cap = max(1, (int) config('zatca.retry.outage.max_delay_seconds', 900));
        $delay = $this->jittered(min($cap, $this->scheduled($workflow, $tries)));

        // Never sleep past the end of the window: the last try happens just before it closes.
        return (int) max(1, min($delay, $room - 1));
    }

    /** Unix time at which outage retries stop. */
    public function windowClosesAt(Workflow $workflow, ?\DateTimeInterface $deadline, ?\DateTimeInterface $firstAttemptAt, ?int $now = null): int
    {
        if ($workflow === Workflow::B2C && $deadline !== null) {
            return $deadline->getTimestamp();
        }
        $start = $firstAttemptAt?->getTimestamp() ?? $now ?? Carbon::now()->getTimestamp();
        $hours = $workflow === Workflow::B2C
            ? (int) config('zatca.b2c.reporting_deadline_hours', 24)
            : (int) config('zatca.retry.outage.b2b_window_hours', 24);

        return $start + max(1, $hours) * 3600;
    }

    private function scheduled(Workflow $workflow, int $attempt): int
    {
        $schedule = array_values(array_map('intval', (array) config('zatca.retry.' . $workflow->value . '.backoff_seconds', [60])));

        return $schedule[max(0, min($attempt - 1, count($schedule) - 1))] ?? 60;
    }

    private function jittered(int $base): int
    {
        return (int) max(1, round($base * (0.5 + (random_int(0, 1000) / 2000))));
    }
}
