<?php

declare(strict_types=1);

use CodingTriplets\Zatca\Laravel\Database\Schema\Tables;
use CodingTriplets\Zatca\Laravel\Database\Schema\ZatcaMigration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Signed / cleared XML is stored in the artifact row itself (storage.driver = database): no file operations inside
 * the issuance transaction, nothing to share between servers, no orphan files after a rollback, and the XML is backed
 * up with the database. `content` holds the (gzip-compressed, optionally encrypted) bytes as base64 text, which works
 * the same on MySQL, MariaDB, PostgreSQL, SQL Server and SQLite. Rows written by the filesystem driver keep
 * `content` NULL and are still read from their disk.
 */
return new class extends ZatcaMigration {
    public function up(): void
    {
        $this->schema()->table(Tables::name('invoice_artifacts'), function (Blueprint $t): void {
            $t->longText('content')->nullable();
            $t->string('encoding', 32)->nullable();   // e.g. "gzip+base64", "gzip+vault+base64"
        });
    }

    public function down(): void
    {
        $this->schema()->table(Tables::name('invoice_artifacts'), function (Blueprint $t): void {
            $t->dropColumn(['content', 'encoding']);
        });
    }
};
