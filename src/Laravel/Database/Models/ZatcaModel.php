<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Database\Models;

use CodingTriplets\Zatca\Laravel\Database\Schema\Tables;
use Illuminate\Database\Eloquent\Model;

/**
 * Base model: resolves the prefixed table name and the configured connection at runtime.
 * Uses the $casts property (not the L11+ casts() method) for Laravel 9-14 compatibility.
 */
abstract class ZatcaModel extends Model
{
    /** Unprefixed table name, e.g. "invoices". */
    protected string $baseTable;

    protected $guarded = ['id'];

    public function getTable(): string
    {
        return Tables::name($this->baseTable);
    }

    public function getConnectionName(): ?string
    {
        return Tables::connection() ?? parent::getConnectionName();
    }
}
