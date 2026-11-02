<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Domain\Enums;

/**
 * Workflow dimension. Legal transitions are defined per workflow
 * (Invoice\B2B\Status, Invoice\B2C\Status); this enum is only the vocabulary.
 */
enum ProcessingStatus: string
{
    case Received = 'RECEIVED';
    case Validating = 'VALIDATING';
    case ValidationFailed = 'VALIDATION_FAILED';
    case ReadyForProcessing = 'READY_FOR_PROCESSING';
    case XmlGenerating = 'XML_GENERATING';
    case XmlGenerated = 'XML_GENERATED';
    case Signing = 'SIGNING';
    case Signed = 'SIGNED';
    case Queued = 'QUEUED';
    case Submitting = 'SUBMITTING';
    case Submitted = 'SUBMITTED';
    case OutcomeUnknown = 'OUTCOME_UNKNOWN';
    case Reported = 'REPORTED';
    case Cleared = 'CLEARED';
    case Rejected = 'REJECTED';
    case Failed = 'FAILED';
    case RetryPending = 'RETRY_PENDING';
    case ManualReview = 'MANUAL_REVIEW';
    case Completed = 'COMPLETED';

    /** ZATCA accepted the current issuance: never resend, never correct (use credit/debit notes). */
    public function isAccepted(): bool
    {
        return in_array($this, [self::Reported, self::Cleared, self::Completed], true);
    }

    public function isTerminal(): bool
    {
        return $this === self::Completed;
    }

    /** States from which a byte-identical resend (Operation A) may be claimed. */
    public function allowsRetrySubmission(): bool
    {
        return in_array($this, [
            self::Queued, self::RetryPending, self::Failed, self::OutcomeUnknown, self::ManualReview,
        ], true);
    }
}
