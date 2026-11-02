<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Database\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Table: {prefix}b2b_clearance_submissions */
class B2bClearanceSubmission extends ZatcaModel
{
    protected string $baseTable = 'b2b_clearance_submissions';

    protected $casts = [
        'claimed_until' => 'datetime',
        'first_attempt_at' => 'datetime',
        'completed_at' => 'datetime',
        'manual' => 'boolean',
    ];

    public function issuance(): BelongsTo
    {
        return $this->belongsTo(InvoiceIssuance::class, 'issuance_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function clearedArtifact(): BelongsTo
    {
        return $this->belongsTo(InvoiceArtifact::class, 'cleared_artifact_id');
    }
}
