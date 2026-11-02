<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Processing\Submission;

use CodingTriplets\Zatca\Core\Contracts\Events\EventDispatcher;
use CodingTriplets\Zatca\Core\Contracts\Persistence\TransactionManager;
use CodingTriplets\Zatca\Core\Contracts\Storage\ArtifactStore;
use CodingTriplets\Zatca\Core\Contracts\Storage\StoredArtifact;
use CodingTriplets\Zatca\Core\Domain\Enums\ArtifactKind;
use CodingTriplets\Zatca\Core\Domain\Enums\AttemptOutcome;
use CodingTriplets\Zatca\Core\Domain\Enums\ClearanceStatus;
use CodingTriplets\Zatca\Core\Domain\Enums\ErrorCategory;
use CodingTriplets\Zatca\Core\Domain\Enums\IssuanceStatus;
use CodingTriplets\Zatca\Core\Domain\Enums\ProcessingStatus;
use CodingTriplets\Zatca\Core\Domain\Enums\ReportingStatus;
use CodingTriplets\Zatca\Core\Domain\Enums\Severity;
use CodingTriplets\Zatca\Core\Domain\Enums\SubmissionStatus;
use CodingTriplets\Zatca\Core\Domain\Enums\Workflow;
use CodingTriplets\Zatca\Core\Domain\Exceptions\FatooraApiException;
use CodingTriplets\Zatca\Core\Events\InvoiceAccepted;
use CodingTriplets\Zatca\Core\Events\InvoiceRejected;
use CodingTriplets\Zatca\Core\Events\InvoiceSubmissionFailed;
use CodingTriplets\Zatca\Core\Fatoora\InvoiceSubmissionResult;
use CodingTriplets\Zatca\Laravel\Database\Models\B2bClearanceSubmission;
use CodingTriplets\Zatca\Laravel\Database\Models\B2cReportingSubmission;
use CodingTriplets\Zatca\Laravel\Database\Models\Device;
use CodingTriplets\Zatca\Laravel\Database\Models\Invoice;
use CodingTriplets\Zatca\Laravel\Database\Models\InvoiceArtifact;
use CodingTriplets\Zatca\Laravel\Database\Models\InvoiceIssuance;
use CodingTriplets\Zatca\Laravel\Database\Models\SubmissionAttempt;
use CodingTriplets\Zatca\Laravel\Database\Models\Warning;
use CodingTriplets\Zatca\Laravel\Invoices\DeviceUnavailable;
use CodingTriplets\Zatca\Laravel\Invoices\QueueNames;
use CodingTriplets\Zatca\Laravel\Invoices\SigningCredentialProvider;
use CodingTriplets\Zatca\Laravel\Processing\ErrorRecorder;
use CodingTriplets\Zatca\Laravel\Processing\Outbox\OutboxRecorder;
use CodingTriplets\Zatca\Laravel\Processing\ProcessingLogger;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Str;

/**
 * Submits one issuance to ZATCA (B2B clearance or B2C reporting) following ARCHITECTURE 14.1:
 *
 *   T3 claim (conditional UPDATE + lease + fencing token)  →  HTTP (outside any transaction)  →  T4 outcome
 *
 * - The exact stored bytes are sent (SHA-256 re-checked). Nothing is regenerated; retries resend the same document.
 * - The outcome write is fenced by the claim token: a worker whose lease expired cannot overwrite a newer result.
 * - Acceptance is decided from ZATCA's body (reporting/clearance status), never from the HTTP status alone.
 * - Rejections are never retried automatically (correction needs a human). Transient failures are retried with
 *   backoff through the outbox; 401/403 and malformed requests stop for manual review.
 */
final class SubmissionRunner
{
    /** `clearance_status_raw` marker: ZATCA answered 303, the B2B document is reported instead ⚠ */
    public const CLEARANCE_DEACTIVATED = 'CLEARANCE_DEACTIVATED';

    private const CLAIMABLE = ['pending', 'unknown'];

    private const FINAL = ['reported', 'reported_with_warnings', 'not_reported', 'cleared', 'cleared_with_warnings', 'not_cleared', 'failed', 'clearance_deactivated'];

    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly ArtifactStore $artifacts,
        private readonly SigningCredentialProvider $credentials,
        private readonly SubmissionGateway $gateway,
        private readonly RetryPolicy $retry,
        private readonly CircuitBreaker $circuit,
        private readonly RateLimiter $limiter,
        private readonly ProcessingLogger $logger,
        private readonly ErrorRecorder $errors,
        private readonly OutboxRecorder $outbox,
        private readonly EventDispatcher $events,
    ) {
    }

    /** @return string the submission status after this run */
    public function run(Workflow $workflow, int $submissionId, bool $manual = false): string
    {
        $model = $workflow === Workflow::B2B ? B2bClearanceSubmission::class : B2cReportingSubmission::class;
        /** @var B2bClearanceSubmission|B2cReportingSubmission|null $submission */
        $submission = $model::query()->find($submissionId);
        if ($submission === null || in_array($submission->status, self::FINAL, true)) {
            return (string) $submission?->status;
        }

        $issuance = InvoiceIssuance::query()->findOrFail($submission->issuance_id);
        $invoice = Invoice::query()->withoutGlobalScopes()->findOrFail($submission->invoice_id);
        if ($invoice->workflow !== $workflow) {
            throw new \LogicException("Invoice {$invoice->public_id} is {$invoice->workflow->value}; it cannot be submitted through the {$workflow->value} pipeline.");
        }
        $device = Device::query()->withoutGlobalScopes()->findOrFail($issuance->device_id);
        $environment = $device->environment;
        $viaReporting = $submission instanceof B2bClearanceSubmission && $submission->clearance_status_raw === self::CLEARANCE_DEACTIVATED;

        // Backpressure: open circuit, paused device, rate limit → try later, without using an attempt.
        $wait = $this->circuit->retryAfter("env:{$environment->value}");
        if ($wait === 0 && $device->submissions_paused_until !== null && $device->submissions_paused_until->isFuture()) {
            $wait = (int) max(1, now()->diffInSeconds($device->submissions_paused_until));
        }
        if ($wait === 0 && ! $this->limiter->attempt("zatca:http:{$environment->value}", max(1, (int) config('zatca.http.max_rps', 10)), static fn () => true, 1)) {
            $wait = 1;
        }
        if ($wait > 0) {
            $this->reschedule($workflow, $submission, $issuance, $invoice, $wait, 'throttled');

            return (string) $submission->status;
        }

        // T3: claim.
        $token = (string) Str::uuid();
        $attempt = $this->transactions->run(function () use ($model, $submission, $token, $workflow, $invoice, $issuance, $environment, $manual, $viaReporting): ?SubmissionAttempt {
            $now = now();
            $claimed = $model::query()->whereKey($submission->id)
                ->where(static fn ($q) => $q->whereIn('status', self::CLAIMABLE)
                    ->orWhere(static fn ($q) => $q->where('status', 'in_flight')->where('claimed_until', '<', $now)))
                ->where(static fn ($q) => $q->whereNull('claimed_until')->orWhere('claimed_until', '<', $now))
                ->update([
                    'status' => 'in_flight',
                    'claim_token' => $token,
                    'claimed_until' => $now->copy()->addSeconds((int) config('zatca.http.timeout', 30) * 3 + 30),
                    'attempts' => \Illuminate\Support\Facades\DB::raw('attempts + 1'),
                    'first_attempt_at' => $submission->first_attempt_at ?? $now,
                    'manual' => $manual,
                    'updated_at' => $now,
                ]);
            if ($claimed !== 1) {
                return null;
            }

            $attemptNumber = (int) $model::query()->whereKey($submission->id)->value('attempts');
            $locked = Invoice::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($invoice->id);
            $this->logger->transition($locked, ['processing_status' => ProcessingStatus::Submitting, 'submission_status' => SubmissionStatus::InFlight], 'submitting', "attempt {$attemptNumber}", (int) $issuance->id);
            $locked->attempts = (int) $locked->attempts + 1;
            $locked->last_submitted_at = $now;
            $locked->save();

            return SubmissionAttempt::query()->create([
                'workflow' => $workflow,
                'submission_id' => $submission->id,
                'invoice_id' => $invoice->id,
                'issuance_id' => $issuance->id,
                'attempt_number' => $attemptNumber,
                'endpoint' => SubmissionGateway::endpoint($workflow, $viaReporting),
                'environment' => $environment->value,
                'request_headers' => ['Accept-Version' => 'V2', 'Clearance-Status' => $workflow === Workflow::B2B && ! $viaReporting ? '1' : '0', 'Authorization' => '[redacted]'],
                'request_body' => ['invoiceHash' => $issuance->invoice_hash, 'uuid' => $issuance->uuid, 'invoice' => '[signed XML artifact]'],
                'started_at' => $now,
                'triggered_by' => $manual ? 'manual' : 'system',
                'idempotency_key' => "{$issuance->uuid}:{$attemptNumber}",
            ]);
        });

        if ($attempt === null) {
            return (string) $model::query()->whereKey($submission->id)->value('status');   // another worker owns it
        }

        // HTTP, outside any transaction.
        $started = microtime(true);
        $result = null;
        $failure = null;
        try {
            $xml = $this->signedXml($issuance);
            $result = $this->gateway->submit($workflow, $environment, $xml, (string) $issuance->invoice_hash, (string) $issuance->uuid, $this->credentials->submission($device), $viaReporting);
        } catch (\Throwable $e) {
            // Never leave the claim hanging: every failure is turned into a recorded outcome below.
            $failure = $e;
        }
        $durationMs = (int) round((microtime(true) - $started) * 1000);

        // T4: outcome, fenced by the claim token.
        return $this->record($workflow, $model, (int) $submission->id, $token, $attempt, $invoice, $issuance, $device, $result, $failure, $durationMs, $viaReporting);
    }

    /**
     * @param class-string<B2bClearanceSubmission|B2cReportingSubmission> $model
     */
    private function record(
        Workflow $workflow,
        string $model,
        int $submissionId,
        string $token,
        SubmissionAttempt $attempt,
        Invoice $invoice,
        InvoiceIssuance $issuance,
        Device $device,
        ?InvoiceSubmissionResult $result,
        ?\Throwable $failure,
        int $durationMs,
        bool $viaReporting = false,
    ): string {
        $env = "env:{$device->environment->value}";
        $outcome = $this->classify($result, $failure);
        $status303 = $result?->httpStatus === 303 || ($failure instanceof FatooraApiException && $failure->httpStatus === 303);
        if ($workflow === Workflow::B2B && ! $viaReporting && $status303) {
            $outcome = AttemptOutcome::ClearanceDeactivated;
        }
        $cleared = $workflow === Workflow::B2B && ! $viaReporting;   // false: the reporting endpoint was used
        if (in_array($outcome, [AttemptOutcome::Transient, AttemptOutcome::Unknown], true) && $failure instanceof FatooraApiException && $failure->httpStatus === null) {
            $this->circuit->failure($env);
        } elseif ($result !== null) {
            $this->circuit->success($env);
        }

        $events = [];
        $status = $this->transactions->run(function () use ($workflow, $model, $submissionId, $token, $attempt, $invoice, $issuance, $device, $result, $failure, $durationMs, $outcome, $cleared, &$events): string {
            /** @var B2bClearanceSubmission|B2cReportingSubmission $submission */
            $submission = $model::query()->whereKey($submissionId)->lockForUpdate()->firstOrFail();
            $attempt->forceFill([
                'response_body' => $result !== null ? ErrorRecorder::sanitize($result->body) : null,
                'http_status' => $result?->httpStatus ?? ($failure instanceof FatooraApiException ? $failure->httpStatus : null),
                'zatca_status' => $result !== null ? ($result->reportingStatus ?? $result->clearanceStatus ?? $result->validationStatus) : null,
                'outcome' => $outcome,
                'error_code' => $failure !== null ? mb_substr(class_basename($failure), 0, 64) : ($result?->errors[0]['code'] ?? null),
                'error_message' => $failure !== null ? mb_substr($failure->getMessage(), 0, 2000) : ($result?->errors[0]['message'] ?? null),
                'completed_at' => now(),
                'duration_ms' => $durationMs,
            ])->save();

            if ($submission->claim_token !== $token) {
                // Our lease expired and another worker took over: keep the log, do not touch the state.
                return (string) $submission->status;
            }

            /** @var Invoice $locked */
            $locked = Invoice::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($invoice->id);
            $context = ['invoice' => $locked, 'issuance_id' => (int) $issuance->id, 'attempt_number' => (int) $attempt->attempt_number, 'submission_attempt_id' => (int) $attempt->id];

            switch ($outcome) {
                case AttemptOutcome::Accepted:
                case AttemptOutcome::AcceptedWithWarnings:
                    assert($result !== null);
                    $warnings = $outcome === AttemptOutcome::AcceptedWithWarnings;
                    $raw = $cleared ? ($warnings ? 'cleared_with_warnings' : 'cleared') : ($warnings ? 'reported_with_warnings' : 'reported');
                    $fields = ['status' => $raw, 'completed_at' => now(), 'claimed_until' => null];
                    if ($cleared) {
                        $fields['clearance_status_raw'] = $result->clearanceStatus;
                        if ($result->clearedInvoice !== null) {
                            $fields['cleared_artifact_id'] = $this->storeCleared($locked, $issuance, $result->clearedInvoice);
                        }
                        $locked->cleared_at = now();
                        $this->logger->transition($locked, [
                            'processing_status' => ProcessingStatus::Cleared,
                            'submission_status' => SubmissionStatus::Delivered,
                            'clearance_status' => $warnings ? ClearanceStatus::ClearedWithWarnings : ClearanceStatus::Cleared,
                        ], 'cleared', issuanceId: (int) $issuance->id);
                    } else {
                        if ($workflow === Workflow::B2C) {
                            $fields['reporting_status_raw'] = $result->reportingStatus;
                        }
                        $locked->reported_at = now();
                        $this->logger->transition($locked, [
                            'processing_status' => ProcessingStatus::Reported,
                            'submission_status' => SubmissionStatus::Delivered,
                            'reporting_status' => $warnings ? ReportingStatus::ReportedWithWarnings : ReportingStatus::Reported,
                        ], 'reported', issuanceId: (int) $issuance->id);
                    }
                    $submission->forceFill($fields)->save();
                    $issuance->forceFill(['status' => $warnings ? IssuanceStatus::AcceptedWithWarnings : IssuanceStatus::Accepted])->save();
                    $this->storeWarnings($locked, $issuance, $result);
                    $locked->zatca_response_status = $result->reportingStatus ?? $result->clearanceStatus;
                    $locked->next_retry_at = null;
                    $events[] = new InvoiceAccepted((int) $locked->id, (string) $locked->public_id, (int) $issuance->id, $workflow->value, $raw, $warnings, sourceType: $locked->source_type, sourceId: $locked->source_id === null ? null : (string) $locked->source_id);
                    break;

                case AttemptOutcome::Rejected:
                    assert($result !== null);
                    $raw = $cleared ? 'not_cleared' : 'not_reported';
                    $submission->forceFill([
                        'status' => $raw,
                        'completed_at' => now(),
                        'claimed_until' => null,
                    ] + ($cleared ? ['clearance_status_raw' => $result->clearanceStatus] : ($workflow === Workflow::B2C ? ['reporting_status_raw' => $result->reportingStatus] : [])))->save();
                    $issuance->forceFill(['status' => IssuanceStatus::Rejected])->save();
                    $this->logger->transition($locked, ['processing_status' => ProcessingStatus::Rejected, 'submission_status' => SubmissionStatus::Delivered]
                        + ($cleared ? ['clearance_status' => ClearanceStatus::NotCleared] : ['reporting_status' => ReportingStatus::NotReported]), 'rejected', issuanceId: (int) $issuance->id);
                    foreach ($result->errors ?: [['code' => 'REJECTED', 'category' => '', 'message' => 'ZATCA rejected the document without error details.']] as $error) {
                        $this->errors->record(ErrorCategory::ZatcaRejection, Severity::Error, $error['code'] ?: null, $error['message'], "zatca.{$workflow->value}", $context + ['details' => ['category' => $error['category']]]);
                    }
                    $this->storeWarnings($locked, $issuance, $result);
                    $locked->zatca_response_status = $result->reportingStatus ?? $result->clearanceStatus ?? $result->validationStatus;
                    $locked->next_retry_at = null;
                    $events[] = new InvoiceRejected((int) $locked->id, (string) $locked->public_id, (int) $issuance->id, $workflow->value, $raw, sourceType: $locked->source_type, sourceId: $locked->source_id === null ? null : (string) $locked->source_id);
                    break;

                case AttemptOutcome::ClearanceDeactivated:
                    // ZATCA switched clearance off: the same signed standard document is reported instead ⚠.
                    $report = config('zatca.b2b.on_clearance_deactivated', 'report') === 'report';
                    $submission->forceFill([
                        'status' => $report ? 'pending' : 'failed',
                        'clearance_status_raw' => self::CLEARANCE_DEACTIVATED,
                        'claimed_until' => null,
                    ])->save();
                    $this->logger->transition($locked, [
                        'processing_status' => $report ? ProcessingStatus::Queued : ProcessingStatus::ManualReview,
                        'submission_status' => $report ? SubmissionStatus::Pending : SubmissionStatus::FailedPermanent,
                        'clearance_status' => ClearanceStatus::ClearanceDeactivated,
                        'reporting_status' => ReportingStatus::Pending,
                    ], 'clearance_deactivated', $report ? 'ZATCA answered 303: reporting the document instead' : 'ZATCA answered 303: waiting for a manual retry (reporting)', (int) $issuance->id);
                    Warning::query()->create([
                        'tenant_id' => (string) $locked->tenant_id, 'invoice_id' => $locked->id, 'issuance_id' => $issuance->id,
                        'workflow' => $workflow, 'source' => 'zatca', 'code' => 'CLEARANCE_DEACTIVATED',
                        'message' => 'ZATCA answered 303 (clearance switched off); the standard invoice is reported instead.',
                    ]);
                    $locked->warning_count = (int) $locked->warning_count + 1;
                    $locked->next_retry_at = null;
                    if ($report) {
                        $this->outbox->record('submission', (int) $submission->id, OutboxRecorder::EVENT_SUBMIT, QueueNames::submission($workflow), "submit:{$issuance->uuid}:report", ['workflow' => $workflow->value], tenantId: (string) $locked->tenant_id);
                    }
                    $events[] = new \CodingTriplets\Zatca\Core\Events\ClearanceDeactivated((int) $locked->id, (string) $locked->public_id, (int) $issuance->id, sourceType: $locked->source_type, sourceId: $locked->source_id === null ? null : (string) $locked->source_id);
                    break;

                case AttemptOutcome::Transient:
                case AttemptOutcome::Unknown:
                    $deadline = $submission instanceof B2cReportingSubmission ? $submission->deadline_at : null;
                    $delay = $this->retry->delay($workflow, (int) $attempt->attempt_number, $deadline);
                    $message = $failure?->getMessage() ?? 'unknown outcome';
                    if ($delay === null) {
                        $submission->forceFill(['status' => 'failed', 'completed_at' => now(), 'claimed_until' => null])->save();
                        $this->logger->transition($locked, ['processing_status' => ProcessingStatus::Failed, 'submission_status' => SubmissionStatus::FailedPermanent], 'attempts_exhausted', $message, (int) $issuance->id);
                        $this->errors->record(ErrorCategory::ApiConnection, Severity::Critical, 'attempts_exhausted', "Submission failed after {$attempt->attempt_number} attempts: {$message}", 'submission', $context);
                        $locked->next_retry_at = null;
                        $events[] = new InvoiceSubmissionFailed((int) $locked->id, (string) $locked->public_id, (int) $issuance->id, $workflow->value, 'attempts_exhausted', sourceType: $locked->source_type, sourceId: $locked->source_id === null ? null : (string) $locked->source_id);
                        break;
                    }
                    $unknown = $outcome === AttemptOutcome::Unknown;
                    $submission->forceFill(['status' => $unknown ? 'unknown' : 'pending', 'claimed_until' => null])->save();
                    $this->logger->transition($locked, [
                        'processing_status' => $unknown ? ProcessingStatus::OutcomeUnknown : ProcessingStatus::RetryPending,
                        'submission_status' => $unknown ? SubmissionStatus::Unknown : SubmissionStatus::FailedTransient,
                    ], $unknown ? 'outcome_unknown' : 'retry_scheduled', "{$message} (next attempt in {$delay}s)", (int) $issuance->id);
                    $this->errors->record(ErrorCategory::ApiConnection, Severity::Warning, $failure instanceof FatooraApiException && $failure->httpStatus !== null ? (string) $failure->httpStatus : 'transport', $message, 'submission', $context);
                    $locked->next_retry_at = now()->addSeconds($delay);
                    $this->outbox->record('submission', (int) $submission->id, OutboxRecorder::EVENT_SUBMIT, QueueNames::retry($workflow), "submit:{$issuance->uuid}:" . ((int) $attempt->attempt_number + 1), ['workflow' => $workflow->value], now()->addSeconds($delay), (string) $locked->tenant_id);
                    break;

                case AttemptOutcome::Auth:
                case AttemptOutcome::ClientError:
                default:
                    $auth = $outcome === AttemptOutcome::Auth;
                    $submission->forceFill(['status' => 'failed', 'claimed_until' => null])->save();
                    $this->logger->transition($locked, ['processing_status' => ProcessingStatus::ManualReview, 'submission_status' => SubmissionStatus::FailedPermanent], $auth ? 'authentication_failed' : 'request_refused', $failure?->getMessage(), (int) $issuance->id);
                    $this->errors->record(
                        $auth ? ErrorCategory::Authentication : ErrorCategory::ZatcaRejection,
                        $auth ? Severity::Critical : Severity::Error,
                        $failure instanceof FatooraApiException && $failure->httpStatus !== null ? (string) $failure->httpStatus : ($result !== null ? (string) $result->httpStatus : class_basename((string) ($failure !== null ? get_class($failure) : 'error'))),
                        $failure?->getMessage() ?? 'ZATCA refused the request.',
                        'submission',
                        $context + ['device_id' => (int) $device->id],
                    );
                    $locked->next_retry_at = null;
                    $events[] = new InvoiceSubmissionFailed((int) $locked->id, (string) $locked->public_id, (int) $issuance->id, $workflow->value, $auth ? 'authentication' : 'request_refused', sourceType: $locked->source_type, sourceId: $locked->source_id === null ? null : (string) $locked->source_id);
                    break;
            }

            $locked->save();

            return (string) $submission->status;
        });

        foreach ($events as $event) {
            $this->events->dispatchAfterCommit($event);
        }

        return $status;
    }

    private function classify(?InvoiceSubmissionResult $result, ?\Throwable $failure): AttemptOutcome
    {
        if ($result !== null) {
            if ($result->isAccepted()) {
                return $result->hasWarnings() ? AttemptOutcome::AcceptedWithWarnings : AttemptOutcome::Accepted;
            }
            if ($result->httpStatus === 400 || in_array($result->reportingStatus, ['NOT_REPORTED'], true) || in_array($result->clearanceStatus, ['NOT_CLEARED'], true) || $result->validationStatus === 'ERROR') {
                return AttemptOutcome::Rejected;
            }

            return AttemptOutcome::ClientError;   // 303 for B2B is mapped to ClearanceDeactivated by the caller
        }

        if ($failure instanceof FatooraApiException) {
            if (in_array($failure->httpStatus, [401, 403], true)) {
                return AttemptOutcome::Auth;
            }
            if ($failure->httpStatus === null) {
                return AttemptOutcome::Transient;    // connection failed / timed out: the identical document is resent
            }
            if ($failure->retryable) {
                return AttemptOutcome::Transient;    // 429 / 5xx
            }
            if (in_array($failure->httpStatus, [200, 202], true)) {
                return AttemptOutcome::Unknown;      // success status without a readable result
            }

            return AttemptOutcome::ClientError;
        }

        if ($failure instanceof DeviceUnavailable) {
            return AttemptOutcome::Auth;
        }

        // Unexpected error (not raised by the package): the request may or may not have reached ZATCA.
        // Treated as an unknown outcome, which resends the identical document.
        return $failure instanceof \CodingTriplets\Zatca\Core\Domain\Exceptions\ZatcaException ? AttemptOutcome::ClientError : AttemptOutcome::Unknown;
    }

    private function signedXml(InvoiceIssuance $issuance): string
    {
        $artifact = InvoiceArtifact::query()->where('issuance_id', $issuance->id)->where('kind', ArtifactKind::SignedXml)->firstOrFail();

        return $this->artifacts->get(new StoredArtifact((string) $artifact->disk, (string) $artifact->path, (string) $artifact->sha256, (int) $artifact->bytes, (bool) $artifact->encrypted));
    }

    private function storeCleared(Invoice $invoice, InvoiceIssuance $issuance, string $clearedBase64): ?int
    {
        $xml = base64_decode($clearedBase64, true);
        if ($xml === false || $xml === '') {
            return null;
        }
        $stored = $this->artifacts->putImmutable(implode('/', array_filter([(string) $invoice->tenant_id, 'c' . $invoice->company_id, 'd' . $issuance->device_id, 'cleared', now()->format('Y/m')])), $xml);

        return (int) InvoiceArtifact::query()->create([
            'issuance_id' => $issuance->id,
            'invoice_id' => $invoice->id,
            'kind' => ArtifactKind::ClearedXml,
            'disk' => $stored->disk,
            'path' => $stored->path,
            'sha256' => $stored->sha256,
            'bytes' => $stored->bytes,
            'encrypted' => $stored->encrypted,
            'mime' => 'application/xml',
        ])->id;
    }

    private function storeWarnings(Invoice $invoice, InvoiceIssuance $issuance, InvoiceSubmissionResult $result): void
    {
        foreach ($result->warnings as $w) {
            Warning::query()->create([
                'tenant_id' => (string) $invoice->tenant_id,
                'invoice_id' => $invoice->id,
                'issuance_id' => $issuance->id,
                'workflow' => $invoice->workflow,
                'source' => 'zatca',
                'code' => mb_substr($w['code'], 0, 64),
                'message' => $w['message'],
                'details' => ['category' => $w['category']],
            ]);
        }
        if ($result->warnings !== []) {
            $invoice->warning_count = (int) $invoice->warning_count + count($result->warnings);
        }
    }

    private function reschedule(Workflow $workflow, B2bClearanceSubmission|B2cReportingSubmission $submission, InvoiceIssuance $issuance, Invoice $invoice, int $seconds, string $reason): void
    {
        $slot = (int) floor(time() / max(1, $seconds));
        $this->outbox->record('submission', (int) $submission->id, OutboxRecorder::EVENT_SUBMIT, QueueNames::submission($workflow), "submit:{$issuance->uuid}:{$reason}:{$slot}", ['workflow' => $workflow->value], now()->addSeconds($seconds), (string) $invoice->tenant_id);
    }
}
