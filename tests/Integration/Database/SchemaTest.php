<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Tests\Integration\Database;

use CodingTriplets\Zatca\Tests\Integration\IntegrationTestCase;
use Illuminate\Support\Facades\Schema;

final class SchemaTest extends IntegrationTestCase
{
    private const TABLES = [
        'companies', 'vat_registrations', 'branches', 'devices', 'device_keys', 'certificates',
        'device_onboardings', 'device_renewals', 'invoice_counters', 'invoices', 'invoice_versions',
        'invoice_drafts', 'invoice_issuances', 'chain_entries', 'invoice_artifacts',
        'b2b_clearance_submissions', 'b2c_reporting_submissions', 'submission_attempts',
        'error_groups', 'errors', 'warnings', 'processing_logs', 'audit_logs',
        'outbox', 'bulk_operations', 'bulk_operation_items', 'stats_daily', 'settings',
        'invoice_lines', 'invoice_vat_breakdown',
    ];

    public function test_all_package_tables_are_created_with_the_default_prefix(): void
    {
        foreach (self::TABLES as $table) {
            self::assertTrue(Schema::hasTable('zatca_' . $table), "zatca_{$table} missing");
        }
    }

    public function test_mysql_tables_use_innodb_even_when_the_server_defaults_to_myisam(): void
    {
        if (self::driver() !== 'mysql') {
            self::markTestSkipped('MySQL / MariaDB only.');
        }

        $engines = \Illuminate\Support\Facades\DB::table('information_schema.tables')
            ->where('table_schema', \Illuminate\Support\Facades\DB::getDatabaseName())
            ->where('table_name', 'like', 'zatca\\_%')
            ->distinct()->pluck('engine')->all();

        self::assertSame(['InnoDB'], $engines);
    }

    public function test_invoice_table_has_every_status_dimension(): void
    {
        self::assertTrue(Schema::hasColumns('zatca_invoices', [
            'processing_status', 'validation_status', 'submission_status',
            'reporting_status', 'clearance_status', 'zatca_response_status',
        ]));
    }

    public function test_xml_is_not_stored_in_the_invoice_table(): void
    {
        foreach (Schema::getColumnListing('zatca_invoices') as $column) {
            self::assertStringNotContainsString('xml', $column);
        }
    }

    public function test_migrations_roll_back_cleanly(): void
    {
        $this->artisan('migrate:rollback')->assertExitCode(0);

        foreach (self::TABLES as $table) {
            self::assertFalse(Schema::hasTable('zatca_' . $table), "zatca_{$table} not dropped");
        }
    }
}
