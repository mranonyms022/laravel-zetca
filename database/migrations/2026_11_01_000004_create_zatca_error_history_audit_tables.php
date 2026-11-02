<?php

declare(strict_types=1);

use CodingTriplets\Zatca\Laravel\Database\Schema\Tables;
use CodingTriplets\Zatca\Laravel\Database\Schema\ZatcaMigration;
use Illuminate\Database\Schema\Blueprint;

return new class extends ZatcaMigration {
    public function up(): void
    {
        $this->create('error_groups', function (Blueprint $t): void {
            $t->id();
            $this->tenant($t);
            $t->char('fingerprint', 40);
            $t->string('workflow', 3)->nullable();
            $t->string('category', 32);
            $t->string('code', 64)->nullable();
            $t->text('normalized_message');
            $t->unsignedBigInteger('occurrences')->default(0);
            $t->unsignedBigInteger('open_count')->default(0);
            $t->dateTime('first_seen_at');
            $t->dateTime('last_seen_at');
            $this->datetimes($t);
            $t->unique(['tenant_id', 'fingerprint'], Tables::index('eg_uk'));
            $t->index(['open_count', 'last_seen_at'], Tables::index('eg_open'));
        });

        $this->create('errors', function (Blueprint $t): void {
            $t->id();
            $this->tenant($t);
            $t->unsignedBigInteger('invoice_id')->nullable();
            $t->unsignedBigInteger('issuance_id')->nullable();
            $t->unsignedBigInteger('device_id')->nullable();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->string('workflow', 3)->nullable();
            $t->string('category', 32);
            $t->string('severity', 16);
            $t->string('code', 64)->nullable();
            $t->text('message');
            $t->json('details')->nullable();                     // sanitized
            $t->string('source', 128);
            $t->unsignedInteger('attempt_number')->nullable();
            $t->unsignedBigInteger('submission_attempt_id')->nullable();
            $t->unsignedBigInteger('group_id')->nullable();
            $t->char('fingerprint', 40);
            $t->boolean('is_resolved')->default(false);
            $t->dateTime('resolved_at')->nullable();
            $t->unsignedBigInteger('resolved_by')->nullable();
            $t->text('resolution_note')->nullable();
            $this->datetimes($t);
            $t->index(['is_resolved', 'severity', 'created_at'], Tables::index('err_open'));
            $t->index('invoice_id', Tables::index('err_invoice'));
            $t->index('group_id', Tables::index('err_group'));
            $t->index(['workflow', 'category', 'created_at'], Tables::index('err_wf_cat'));
            $t->index(['device_id', 'created_at'], Tables::index('err_device'));
            Tables::foreign($t, 'invoice_id', 'invoices', 'err_invoice');
            Tables::foreign($t, 'group_id', 'error_groups', 'err_group');
        });

        $this->create('warnings', function (Blueprint $t): void {
            $t->id();
            $this->tenant($t);
            $t->unsignedBigInteger('invoice_id');
            $t->unsignedBigInteger('issuance_id')->nullable();
            $t->string('workflow', 3);
            $t->string('source', 16);                             // local | zatca
            $t->string('code', 64)->nullable();
            $t->text('message');
            $t->json('details')->nullable();
            $t->boolean('is_acknowledged')->default(false);
            $t->unsignedBigInteger('acknowledged_by')->nullable();
            $t->dateTime('acknowledged_at')->nullable();
            $this->datetimes($t);
            $t->index(['is_acknowledged', 'created_at'], Tables::index('warn_ack'));
            $t->index('invoice_id', Tables::index('warn_invoice'));
            Tables::foreign($t, 'invoice_id', 'invoices', 'warn_invoice');
        });

        // Status history across all dimensions (append-only).
        $this->create('processing_logs', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('invoice_id');
            $t->unsignedBigInteger('issuance_id')->nullable();
            $t->string('workflow', 3);
            $t->string('dimension', 32);
            $t->string('event', 64);
            $t->string('previous_status', 32)->nullable();
            $t->string('new_status', 32)->nullable();
            $t->text('message')->nullable();
            $t->json('metadata')->nullable();
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('job_id', 191)->nullable();
            $this->createdAt($t);
            $t->index(['invoice_id', 'id'], Tables::index('plog_invoice'));
            Tables::foreign($t, 'invoice_id', 'invoices', 'plog_invoice');
        });

        // Compliance audit trail (append-only, hash-chained per tenant).
        $this->create('audit_logs', function (Blueprint $t): void {
            $t->id();
            $this->tenant($t);
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('actor_type', 64)->default('system');
            $t->string('action', 64);
            $t->string('subject_type', 64);
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->json('changes')->nullable();
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 512)->nullable();
            $t->char('prev_hash', 64);
            $t->char('row_hash', 64);
            $this->createdAt($t);
            $t->index(['subject_type', 'subject_id'], Tables::index('audit_subject'));
            $t->index(['actor_id', 'created_at'], Tables::index('audit_actor'));
            $t->unique('row_hash', Tables::index('audit_hash_uk'));
        });
    }

    public function down(): void
    {
        $this->drop('audit_logs', 'processing_logs', 'warnings', 'errors', 'error_groups');
    }
};
