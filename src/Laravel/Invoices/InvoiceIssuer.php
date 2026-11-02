<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Invoices;

use CodingTriplets\Zatca\Core\Domain\Enums\Workflow;
use CodingTriplets\Zatca\Core\Invoice\Shared\Validation\InvoiceValidationException;
use CodingTriplets\Zatca\Laravel\Database\Models\B2bClearanceSubmission;
use CodingTriplets\Zatca\Laravel\Database\Models\B2cReportingSubmission;
use CodingTriplets\Zatca\Laravel\Processing\Submission\SubmissionRunner;

/**
 * Public API for one workflow: `Zatca::b2c()` or `Zatca::b2b()`.
 *
 * issue():  intake + validation + issuance in the request (milliseconds, no ZATCA call), then
 *           B2C: reporting on the queue (`zatca.b2c.reporting_mode = queue`, default) or inside the request (`sync`);
 *           B2B: clearance on the queue. The document must not be shared with a B2B buyer before it is CLEARED.
 * submit(): intake + validation only; generation and submission run on the queues.
 *
 * Both are idempotent per `external_invoice_id` (default: invoice_number): a retry returns the original result.
 */
final class InvoiceIssuer
{
    public function __construct(
        private readonly Workflow $workflow,
        private readonly InvoiceIntakeService $intake,
        private readonly InvoiceProcessor $processor,
        private readonly SubmissionRunner $runner,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @param array{device?: int|string, external_id?: string, actor_id?: ?int} $options
     */
    public function issue(array $payload, array $options = []): IssueResult
    {
        $intake = $this->intake->receive($this->workflow, $payload, $options);
        $invoice = $intake->invoice;

        if (! $intake->created || ($intake->validation !== null && ! $intake->validation->isValid())) {
            return IssueResult::fromInvoice($invoice, ! $intake->created);
        }

        $sync = $this->workflow === Workflow::B2C && config('zatca.b2c.reporting_mode', 'queue') === 'sync';
        try {
            $issuance = $this->processor->issue($invoice, $options['actor_id'] ?? null, $sync ? 120 : 0);
        } catch (InvoiceValidationException|DeviceUnavailable|\CodingTriplets\Zatca\Core\Domain\Exceptions\CredentialException) {
            return IssueResult::fromInvoice($invoice, false);
        }

        if ($sync) {
            $submission = B2cReportingSubmission::query()->where('issuance_id', $issuance->id)->first();
            if ($submission !== null) {
                $this->runner->run(Workflow::B2C, (int) $submission->id);
            }
        }

        return IssueResult::fromInvoice($invoice, false);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array{device?: int|string, external_id?: string, actor_id?: ?int} $options
     */
    public function submit(array $payload, array $options = []): IssueResult
    {
        $intake = $this->intake->receive($this->workflow, $payload, $options + ['queue_processing' => true]);

        return IssueResult::fromInvoice($intake->invoice, ! $intake->created);
    }

    /** Validation only: no record, no ICV. */
    public function validate(array $payload, array $options = []): \CodingTriplets\Zatca\Core\Invoice\Shared\Validation\ValidationResult
    {
        return app(InvoiceGenerators::class)->for($this->workflow)->validate($payload);
    }

    /** Runs the pending submission of an issued invoice now (e.g. from a command or a test). */
    public function submitNow(string $publicId): string
    {
        $invoice = \CodingTriplets\Zatca\Laravel\Database\Models\Invoice::query()->where('public_id', $publicId)->firstOrFail();
        $model = $this->workflow === Workflow::B2B ? B2bClearanceSubmission::class : B2cReportingSubmission::class;
        $submission = $model::query()->where('issuance_id', $invoice->current_issuance_id)->firstOrFail();

        return $this->runner->run($this->workflow, (int) $submission->id, manual: true);
    }
}
