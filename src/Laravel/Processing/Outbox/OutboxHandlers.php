<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Processing\Outbox;

use CodingTriplets\Zatca\Core\Domain\Enums\Workflow;
use CodingTriplets\Zatca\Laravel\Database\Models\OutboxMessage;
use CodingTriplets\Zatca\Laravel\Invoices\InvoiceProcessor;
use CodingTriplets\Zatca\Laravel\Processing\Submission\SubmissionRunner;
use Illuminate\Contracts\Container\Container;

/** Routes outbox messages to their handlers. Every handler re-reads state and is idempotent. */
final class OutboxHandlers
{
    public function __construct(private readonly Container $container)
    {
    }

    public function handle(OutboxMessage $message): void
    {
        $payload = (array) $message->payload;

        match ([$message->aggregate_type, $message->event_type]) {
            ['invoice', OutboxRecorder::EVENT_PROCESS] => $this->container->make(InvoiceProcessor::class)->process((int) $message->aggregate_id),
            ['submission', OutboxRecorder::EVENT_SUBMIT] => $this->container->make(SubmissionRunner::class)->run(
                Workflow::from((string) ($payload['workflow'] ?? '')),
                (int) $message->aggregate_id,
            ),
            default => throw new \UnexpectedValueException("No outbox handler for {$message->aggregate_type}.{$message->event_type}."),
        };
    }
}
