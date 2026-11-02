<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Processing\Submission;

use CodingTriplets\Zatca\Core\Domain\Enums\Workflow;

/**
 * Backoff for transient submission failures (ARCHITECTURE 15.3).
 *  - schedule from `zatca.retry.{b2b|b2c}.backoff_seconds`, with full jitter (0.5x–1.0x) to avoid thundering herds;
 *  - B2C: the schedule is compressed so attempts stay before `deadline - b2c_deadline_margin_hours`;
 *  - returns null when the attempts are exhausted.
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

        $schedule = array_values(array_map('intval', (array) ($config['backoff_seconds'] ?? [60])));
        $base = $schedule[min($attemptsSoFar - 1, count($schedule) - 1)] ?? 60;
        $delay = (int) max(1, round($base * (0.5 + (random_int(0, 1000) / 2000))));

        if ($workflow === Workflow::B2C && $deadline !== null) {
            $now ??= new \DateTimeImmutable();
            $latest = $deadline->getTimestamp() - (int) config('zatca.retry.b2c_deadline_margin_hours', 4) * 3600;
            $room = $latest - $now->getTimestamp();
            if ($room > 0) {
                $delay = min($delay, max(1, (int) floor($room / max(1, $max - $attemptsSoFar))));
            } else {
                // Inside the safety margin (or past the deadline): keep trying at the shortest interval.
                $delay = min($delay, max(1, $schedule[0] ?? 30));
            }
        }

        return $delay;
    }
}
