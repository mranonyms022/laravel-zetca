<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Processing;

use CodingTriplets\Zatca\Laravel\Database\Models\Invoice;
use CodingTriplets\Zatca\Laravel\Database\Models\ProcessingLog;

/** Append-only status history for every status dimension of an invoice. */
final class ProcessingLogger
{
    /** @param array<string, mixed> $metadata */
    public function log(
        Invoice $invoice,
        string $dimension,
        string $event,
        ?string $from,
        ?string $to,
        ?string $message = null,
        array $metadata = [],
        ?int $issuanceId = null,
        ?int $actorId = null,
        ?string $jobId = null,
    ): void {
        ProcessingLog::query()->create([
            'invoice_id' => $invoice->id,
            'issuance_id' => $issuanceId,
            'workflow' => $invoice->workflow,
            'dimension' => $dimension,
            'event' => $event,
            'previous_status' => $from,
            'new_status' => $to,
            'message' => $message !== null ? mb_substr($message, 0, 2000) : null,
            'metadata' => $metadata === [] ? null : ErrorRecorder::sanitize($metadata),
            'actor_id' => $actorId,
            'job_id' => $jobId,
        ]);
    }

    /**
     * Applies status changes to the invoice (without saving) and logs each changed dimension.
     *
     * @param array<string, \BackedEnum> $changes column => new status
     */
    public function transition(Invoice $invoice, array $changes, string $event, ?string $message = null, ?int $issuanceId = null, ?int $actorId = null): void
    {
        foreach ($changes as $column => $status) {
            $previous = $invoice->getAttribute($column);
            $from = $previous instanceof \BackedEnum ? (string) $previous->value : ($previous !== null ? (string) $previous : null);
            if ($from === (string) $status->value) {
                continue;
            }
            $invoice->setAttribute($column, $status);
            $this->log($invoice, str_replace('_status', '', $column), $event, $from, (string) $status->value, $message, [], $issuanceId, $actorId);
        }
        $invoice->setAttribute('status_changed_at', now());
    }
}
