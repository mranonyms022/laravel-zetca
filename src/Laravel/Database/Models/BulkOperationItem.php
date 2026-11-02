<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Database\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Table: {prefix}bulk_operation_items */
class BulkOperationItem extends ZatcaModel
{
    protected string $baseTable = 'bulk_operation_items';

    public function operation(): BelongsTo
    {
        return $this->belongsTo(BulkOperation::class, 'bulk_operation_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }
}
