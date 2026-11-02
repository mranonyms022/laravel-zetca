<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Database\Models;

use CodingTriplets\Zatca\Laravel\Database\Models\Concerns\AppendOnly;
use CodingTriplets\Zatca\Laravel\Database\Models\Concerns\BelongsToTenant;

/** Table: {prefix}audit_logs (append-only) */
class AuditLog extends ZatcaModel
{
    use BelongsToTenant, AppendOnly;

    public const UPDATED_AT = null;

    protected string $baseTable = 'audit_logs';

    protected $casts = [
        'changes' => 'array',
    ];
}
