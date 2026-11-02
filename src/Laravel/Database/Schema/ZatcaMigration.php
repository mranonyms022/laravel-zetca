<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Database\Schema;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Base for package migrations: honours the configured connection and table prefix.
 * Conventions (ARCHITECTURE 7.1):
 *  - tenant_id is a non-null string defaulting to '' so composite unique keys work on every engine
 *    (NULLs are distinct in unique indexes on MySQL/PostgreSQL).
 *  - dateTime columns (not timestamp) to avoid the 2038 limit within the retention period.
 *  - append-only tables only have created_at.
 */
abstract class ZatcaMigration extends Migration
{
    public function getConnection(): ?string
    {
        return Tables::connection();
    }

    protected function schema(): \Illuminate\Database\Schema\Builder
    {
        return Schema::connection($this->getConnection());
    }

    protected function create(string $table, \Closure $callback): void
    {
        $this->schema()->create(Tables::name($table), static function (Blueprint $blueprint) use ($callback): void {
            // MySQL/MariaDB: InnoDB is mandatory (transactions, row locks, foreign keys). Some servers still
            // default to MyISAM, which would silently break ICV serialization.
            $blueprint->engine = 'InnoDB';
            $callback($blueprint);
        });
    }

    protected function drop(string ...$tables): void
    {
        foreach ($tables as $table) {
            $this->schema()->dropIfExists(Tables::name($table));
        }
    }

    protected function tenant(Blueprint $table): void
    {
        $table->string('tenant_id', 64)->default('');
    }

    protected function datetimes(Blueprint $table): void
    {
        $table->dateTime('created_at')->nullable();
        $table->dateTime('updated_at')->nullable();
    }

    protected function createdAt(Blueprint $table): void
    {
        $table->dateTime('created_at');
    }

    protected function softDeletes(Blueprint $table): void
    {
        $table->dateTime('deleted_at')->nullable();
    }

    protected function money(Blueprint $table, string $column): void
    {
        $table->decimal($column, 20, 2)->default(0);
    }
}
