<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Database\Schema;

use Illuminate\Database\Schema\Blueprint;

/**
 * Prefix-aware table / index / foreign-key naming shared by migrations and models.
 *
 * Index and FK names are explicit and prefixed because PostgreSQL index names are schema-global
 * and MySQL limits identifiers to 64 characters.
 */
final class Tables
{
    public static function prefix(): string
    {
        return (string) config('zatca.database.table_prefix', 'zatca_');
    }

    public static function name(string $table): string
    {
        return self::prefix() . $table;
    }

    public static function connection(): ?string
    {
        $connection = config('zatca.database.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    public static function index(string $shortName): string
    {
        $name = self::prefix() . $shortName;

        return strlen($name) <= 60 ? $name : substr($name, 0, 51) . '_' . substr(sha1($name), 0, 8);
    }

    public static function foreignKeysEnabled(): bool
    {
        return (bool) config('zatca.database.foreign_keys', true);
    }

    /**
     * Adds a foreign key without cascades (regulatory history is never deleted through cascades;
     * avoiding cascades also keeps SQL Server free of multiple-cascade-path errors).
     */
    public static function foreign(Blueprint $table, string $column, string $referencedTable, string $shortName): void
    {
        if (! self::foreignKeysEnabled()) {
            return;
        }

        $table->foreign($column, self::index('fk_' . $shortName))
            ->references('id')
            ->on(self::name($referencedTable));
    }
}
