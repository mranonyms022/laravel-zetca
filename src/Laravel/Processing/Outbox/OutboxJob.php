<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Processing\Outbox;

use CodingTriplets\Zatca\Core\Domain\Enums\ErrorCategory;
use CodingTriplets\Zatca\Core\Domain\Enums\Severity;
use CodingTriplets\Zatca\Laravel\Database\Models\OutboxMessage;
use CodingTriplets\Zatca\Laravel\Processing\ErrorRecorder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Executes one outbox message. Carries only ids (no invoice data, no credentials).
 *
 * The job never fails on the Laravel queue: handler errors are retried through the outbox with backoff and
 * dead-lettered after `zatca.outbox.max_attempts` (CRITICAL queue error, visible in the console). This keeps
 * retries in one place and survives queue-driver restarts.
 */
final class OutboxJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly int $messageId,
        public readonly string $claimToken,
    ) {
    }

    public function handle(OutboxHandlers $handlers, ErrorRecorder $errors): void
    {
        $message = OutboxMessage::query()->withoutGlobalScopes()->find($this->messageId);
        if ($message === null || $message->processed_at !== null || $message->dead_lettered_at !== null) {
            return;
        }

        try {
            $handlers->handle($message);
        } catch (\Throwable $e) {
            $this->failed($message, $e, $errors);

            return;
        }

        OutboxMessage::query()->withoutGlobalScopes()->whereKey($message->id)->update([
            'processed_at' => now(),
            'claimed_until' => null,
            'updated_at' => now(),
        ]);
    }

    private function failed(OutboxMessage $message, \Throwable $e, ErrorRecorder $errors): void
    {
        $attempts = (int) $message->attempts + 1;
        $max = max(1, (int) config('zatca.outbox.max_attempts', 10));
        $error = mb_substr(get_class($e) . ': ' . $e->getMessage(), 0, 2000);

        if ($attempts >= $max) {
            OutboxMessage::query()->withoutGlobalScopes()->whereKey($message->id)->update([
                'attempts' => $attempts,
                'dead_lettered_at' => now(),
                'claimed_until' => null,
                'last_error' => $error,
                'updated_at' => now(),
            ]);
            $errors->record(ErrorCategory::Queue, Severity::Critical, 'outbox_dead_letter', "Outbox message {$message->event_type} #{$message->id} was dead-lettered: {$e->getMessage()}", 'outbox', [
                'tenant_id' => (string) $message->tenant_id,
                'details' => ['outbox_id' => $message->id, 'aggregate' => "{$message->aggregate_type}#{$message->aggregate_id}", 'attempts' => $attempts],
            ]);

            return;
        }

        // Exponential backoff with jitter: 10s, 40s, 90s, ... capped at 1h.
        $delay = min(3600, 10 * $attempts * $attempts) + random_int(0, 5);
        OutboxMessage::query()->withoutGlobalScopes()->whereKey($message->id)->update([
            'attempts' => $attempts,
            'available_at' => now()->addSeconds($delay),
            'claimed_by' => null,
            'claimed_until' => null,
            'last_error' => $error,
            'updated_at' => now(),
        ]);
    }
}
