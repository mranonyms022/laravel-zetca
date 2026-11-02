<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Tests\Integration\Support;

use CodingTriplets\Zatca\Laravel\Concerns\HasZatcaInvoices;
use Illuminate\Database\Eloquent\Model;

/** A host application's own invoice model with an unrelated table name. */
final class HostBill extends Model
{
    use HasZatcaInvoices;

    protected $table = 'host_sales_bills';

    protected $guarded = [];
}
