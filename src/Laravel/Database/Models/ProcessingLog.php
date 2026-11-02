<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Database\Models;

use CodingTriplets\Zatca\Laravel\Database\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Table: {prefix}processing_logs (append-only) */
class ProcessingLog extends ZatcaModel
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected string $baseTable = 'processing_logs';

    protected $casts = [
        'workflow' => \CodingTriplets\Zatca\Core\Domain\Enums\Workflow::class,
        'metadata' => 'array',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }
}
