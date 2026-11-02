<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Database\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Table: {prefix}b2c_reporting_submissions */
class B2cReportingSubmission extends ZatcaModel
{
    protected string $baseTable = 'b2c_reporting_submissions';

    protected $casts = [
        'claimed_until' => 'datetime',
        'first_attempt_at' => 'datetime',
        'completed_at' => 'datetime',
        'deadline_at' => 'datetime',
        'manual' => 'boolean',
        'deadline_breached' => 'boolean',
    ];

    public function issuance(): BelongsTo
    {
        return $this->belongsTo(InvoiceIssuance::class, 'issuance_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }
}
