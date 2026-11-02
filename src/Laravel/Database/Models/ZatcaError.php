<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Database\Models;

use CodingTriplets\Zatca\Laravel\Database\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Table: {prefix}errors */
class ZatcaError extends ZatcaModel
{
    use BelongsToTenant;

    protected string $baseTable = 'errors';

    protected $casts = [
        'workflow' => \CodingTriplets\Zatca\Core\Domain\Enums\Workflow::class,
        'category' => \CodingTriplets\Zatca\Core\Domain\Enums\ErrorCategory::class,
        'severity' => \CodingTriplets\Zatca\Core\Domain\Enums\Severity::class,
        'details' => 'array',
        'is_resolved' => 'boolean',
        'resolved_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ErrorGroup::class, 'group_id');
    }
}
