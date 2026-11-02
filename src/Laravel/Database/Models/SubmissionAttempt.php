<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Database\Models;

use CodingTriplets\Zatca\Laravel\Database\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Table: {prefix}submission_attempts (append-only) */
class SubmissionAttempt extends ZatcaModel
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected string $baseTable = 'submission_attempts';

    /** @var list<string> columns that may change after insert */
    public array $appendOnlyMutable = ['response_body', 'http_status', 'zatca_status', 'outcome', 'error_code', 'error_message', 'completed_at', 'duration_ms'];

    protected $casts = [
        'workflow' => \CodingTriplets\Zatca\Core\Domain\Enums\Workflow::class,
        'outcome' => \CodingTriplets\Zatca\Core\Domain\Enums\AttemptOutcome::class,
        'request_headers' => 'array',
        'request_body' => 'array',
        'response_body' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function issuance(): BelongsTo
    {
        return $this->belongsTo(InvoiceIssuance::class, 'issuance_id');
    }
}
