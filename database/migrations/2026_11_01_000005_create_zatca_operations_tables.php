<?php

declare(strict_types=1);

use CodingTriplets\Zatca\Laravel\Database\Schema\Tables;
use CodingTriplets\Zatca\Laravel\Database\Schema\ZatcaMigration;
use Illuminate\Database\Schema\Blueprint;

return new class extends ZatcaMigration {
    public function up(): void
    {
        $this->create('outbox', function (Blueprint $t): void {
            $t->id();
            $this->tenant($t);
            $t->string('aggregate_type', 64);
            $t->unsignedBigInteger('aggregate_id');
            $t->string('event_type', 64);
            $t->string('queue', 64);
            $t->json('payload')->nullable();
            $t->string('idempotency_key', 191);
            $t->dateTime('available_at');
            $t->string('claimed_by', 128)->nullable();
            $t->dateTime('claimed_until')->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->dateTime('processed_at')->nullable();
            $t->dateTime('dead_lettered_at')->nullable();
            $t->text('last_error')->nullable();
            $this->datetimes($t);
            $t->unique('idempotency_key', Tables::index('outbox_key_uk'));
            $t->index(['processed_at', 'queue', 'available_at'], Tables::index('outbox_pending'));
            $t->index(['dead_lettered_at'], Tables::index('outbox_dead'));
        });

        $this->create('bulk_operations', function (Blueprint $t): void {
            $t->id();
            $this->tenant($t);
            $t->string('type', 32);                                // retry | revalidate | export ...
            $t->string('workflow', 3)->nullable();
            $t->json('filters');
            $t->string('status', 32);
            $t->unsignedInteger('total')->default(0);
            $t->unsignedInteger('processed')->default(0);
            $t->unsignedInteger('succeeded')->default(0);
            $t->unsignedInteger('skipped')->default(0);
            $t->unsignedInteger('failed')->default(0);
            $t->unsignedBigInteger('requested_by')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->dateTime('completed_at')->nullable();
            $this->datetimes($t);
            $t->index(['status', 'created_at'], Tables::index('bulk_status'));
        });

        $this->create('bulk_operation_items', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('bulk_operation_id');
            $t->unsignedBigInteger('invoice_id');
            $t->string('result', 32)->nullable();
            $t->text('reason')->nullable();
            $this->datetimes($t);
            $t->unique(['bulk_operation_id', 'invoice_id'], Tables::index('bulki_uk'));
            Tables::foreign($t, 'bulk_operation_id', 'bulk_operations', 'bulki_op');
            Tables::foreign($t, 'invoice_id', 'invoices', 'bulki_invoice');
        });

        // Pre-aggregated dashboard rollups (recomputed, never incremented).
        $this->create('stats_daily', function (Blueprint $t): void {
            $t->id();
            $t->date('day');
            $this->tenant($t);
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('device_id');
            $t->string('workflow', 3);
            $t->string('document_type', 3);
            $t->string('environment', 16);
            $t->string('processing_status', 32);
            $t->unsignedBigInteger('count')->default(0);
            $this->money($t, 'total_incl_vat');
            $this->money($t, 'vat_total');
            $t->unsignedInteger('api_p50_ms')->nullable();
            $t->unsignedInteger('api_p95_ms')->nullable();
            $t->unsignedInteger('error_count')->default(0);
            $this->datetimes($t);
            $t->unique(
                ['day', 'tenant_id', 'company_id', 'device_id', 'workflow', 'document_type', 'environment', 'processing_status'],
                Tables::index('stats_uk'),
            );
            $t->index(['tenant_id', 'workflow', 'day'], Tables::index('stats_wf_day'));
        });

        $this->create('settings', function (Blueprint $t): void {
            $t->id();
            $this->tenant($t);
            $t->string('key', 191);
            $t->json('value')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $this->datetimes($t);
            $t->unique(['tenant_id', 'key'], Tables::index('settings_uk'));
        });
    }

    public function down(): void
    {
        $this->drop('settings', 'stats_daily', 'bulk_operation_items', 'bulk_operations', 'outbox');
    }
};
