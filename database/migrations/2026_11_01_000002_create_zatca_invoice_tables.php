<?php

declare(strict_types=1);

use CodingTriplets\Zatca\Laravel\Database\Schema\Tables;
use CodingTriplets\Zatca\Laravel\Database\Schema\ZatcaMigration;
use Illuminate\Database\Schema\Blueprint;

return new class extends ZatcaMigration {
    public function up(): void
    {
        // Chain head per device + environment. Shared by B2B and B2C (one chain per EGS unit).
        $this->create('invoice_counters', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('device_id');
            $t->string('environment', 16);
            $t->unsignedBigInteger('last_icv')->default(0);
            $t->string('last_invoice_hash', 128)->nullable();   // null = genesis
            $t->unsignedBigInteger('last_issuance_id')->nullable();
            $t->unsignedBigInteger('lock_version')->default(0);
            $this->datetimes($t);
            $t->unique(['device_id', 'environment'], Tables::index('ctr_uk'));
            Tables::foreign($t, 'device_id', 'devices', 'ctr_device');
        });

        // Internal primary record: stays the same through corrections (ARCHITECTURE 3.3).
        $this->create('invoices', function (Blueprint $t): void {
            $t->id();
            $t->char('public_id', 26);                          // ULID
            $this->tenant($t);
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('vat_registration_id');
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->unsignedBigInteger('device_id')->nullable();
            $t->string('environment', 16);
            $t->string('workflow', 3);                          // b2b | b2c (immutable)
            $t->string('external_invoice_id', 191);
            $t->string('invoice_number', 191);                  // IRN, cbc:ID
            $t->string('document_type', 3);                     // 388 / 381 / 383
            $t->string('subtype_flags', 7);                     // KSA-2
            $t->unsignedBigInteger('billing_reference_invoice_id')->nullable();
            // Pointers (integrity enforced in repositories; circular FKs avoided for portability).
            $t->unsignedBigInteger('current_version_id')->nullable();
            $t->unsignedBigInteger('current_issuance_id')->nullable();
            $t->unsignedInteger('issuance_count')->default(0);
            $t->uuid('current_uuid')->nullable();
            // Dates
            $t->date('invoice_date');
            $t->dateTime('first_issued_at')->nullable();
            $t->dateTime('last_submitted_at')->nullable();
            $t->dateTime('reported_at')->nullable();
            $t->dateTime('cleared_at')->nullable();
            $t->dateTime('reporting_deadline_at')->nullable();
            $t->dateTime('next_retry_at')->nullable();
            $t->dateTime('status_changed_at')->nullable();
            // Amounts
            $t->char('currency', 3)->default('SAR');
            $this->money($t, 'total_excl_vat');
            $this->money($t, 'vat_total');
            $this->money($t, 'total_incl_vat');
            $t->string('buyer_vat_number', 15)->nullable();
            // Status dimensions
            $t->string('processing_status', 32);
            $t->string('validation_status', 32);
            $t->string('submission_status', 32);
            $t->string('reporting_status', 32);
            $t->string('clearance_status', 32);
            $t->string('zatca_response_status', 64)->nullable();
            // Ops
            $t->unsignedInteger('attempts')->default(0);
            $t->string('last_error_code', 64)->nullable();
            $t->unsignedInteger('unresolved_error_count')->default(0);
            $t->unsignedInteger('warning_count')->default(0);
            $t->boolean('requires_approval')->default(false);
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->dateTime('approved_at')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $this->datetimes($t);
            $this->softDeletes($t);                             // allowed only while issuance_count = 0

            $t->unique('public_id', Tables::index('inv_public_uk'));
            $t->unique(['company_id', 'external_invoice_id'], Tables::index('inv_external_uk'));
            $t->index(['tenant_id', 'workflow', 'invoice_date', 'id'], Tables::index('inv_tenant_wf_date'));
            $t->index(['company_id', 'workflow', 'invoice_date', 'id'], Tables::index('inv_company_wf_date'));
            $t->index(['workflow', 'processing_status', 'status_changed_at'], Tables::index('inv_wf_status'));
            $t->index(['reporting_status', 'reporting_deadline_at'], Tables::index('inv_rep_deadline'));
            $t->index(['clearance_status', 'invoice_date'], Tables::index('inv_clr_date'));
            $t->index(['submission_status', 'next_retry_at'], Tables::index('inv_sub_retry'));
            $t->index(['device_id', 'invoice_date'], Tables::index('inv_device_date'));
            $t->index('invoice_number', Tables::index('inv_number'));
            $t->index('current_uuid', Tables::index('inv_uuid'));
            $t->index('buyer_vat_number', Tables::index('inv_buyer_vat'));
            Tables::foreign($t, 'company_id', 'companies', 'inv_company');
            Tables::foreign($t, 'vat_registration_id', 'vat_registrations', 'inv_vat');
            Tables::foreign($t, 'branch_id', 'branches', 'inv_branch');
            Tables::foreign($t, 'device_id', 'devices', 'inv_device');
            Tables::foreign($t, 'billing_reference_invoice_id', 'invoices', 'inv_billing_ref');
        });

        // Correction history (append-only).
        $this->create('invoice_versions', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('invoice_id');
            $t->unsignedInteger('version');
            $t->unsignedBigInteger('previous_version_id')->nullable();
            $t->string('kind', 16);                             // original | correction
            $t->json('payload');
            $t->char('payload_sha256', 64);
            $t->json('diff')->nullable();                       // RFC 6902 vs previous version
            $t->json('changed_fields')->nullable();             // JSON pointers
            $t->text('reason')->nullable();                     // required for corrections
            $t->json('validation_result')->nullable();
            $t->unsignedBigInteger('issuance_id')->nullable();  // set once an issuance is built from it
            $t->unsignedBigInteger('created_by')->nullable();
            $this->createdAt($t);
            $t->unique(['invoice_id', 'version'], Tables::index('ver_uk'));
            Tables::foreign($t, 'invoice_id', 'invoices', 'ver_invoice');
            Tables::foreign($t, 'previous_version_id', 'invoice_versions', 'ver_prev');
        });

        // Editor drafts: mutable, never used by processing.
        $this->create('invoice_drafts', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('invoice_id');
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('base_version_id');
            $t->json('payload');
            $this->datetimes($t);
            $t->unique(['invoice_id', 'user_id'], Tables::index('draft_uk'));
            Tables::foreign($t, 'invoice_id', 'invoices', 'draft_invoice');
            Tables::foreign($t, 'base_version_id', 'invoice_versions', 'draft_base');
        });

        // One row per legal document instance (UUID + ICV). Identity columns are immutable.
        $this->create('invoice_issuances', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('invoice_id');
            $t->unsignedInteger('sequence');
            $t->unsignedBigInteger('version_id');
            $t->uuid('uuid');
            $t->unsignedBigInteger('device_id');
            $t->string('environment', 16);
            $t->unsignedBigInteger('icv');
            $t->string('previous_invoice_hash', 128);
            $t->string('invoice_hash', 64);
            $t->date('issue_date');
            $t->string('issue_time', 8);                        // HH:MM:SS
            $t->unsignedBigInteger('certificate_id');
            $t->text('signature_value')->nullable();
            $t->dateTime('signing_time')->nullable();
            $t->text('qr_payload')->nullable();
            $t->string('reason', 32);                           // initial | correction | reissue_unchanged
            $t->unsignedBigInteger('supersedes_issuance_id')->nullable();
            $t->string('status', 32);
            $t->unsignedBigInteger('issued_by')->nullable();
            $t->dateTime('issued_at');
            $this->datetimes($t);
            $t->unique(['invoice_id', 'sequence'], Tables::index('iss_seq_uk'));
            $t->unique('uuid', Tables::index('iss_uuid_uk'));
            $t->unique(['device_id', 'environment', 'icv'], Tables::index('iss_icv_uk'));
            $t->index(['status', 'issued_at'], Tables::index('iss_status'));
            Tables::foreign($t, 'invoice_id', 'invoices', 'iss_invoice');
            Tables::foreign($t, 'version_id', 'invoice_versions', 'iss_version');
            Tables::foreign($t, 'device_id', 'devices', 'iss_device');
            Tables::foreign($t, 'certificate_id', 'certificates', 'iss_cert');
            Tables::foreign($t, 'supersedes_issuance_id', 'invoice_issuances', 'iss_supersedes');
        });

        // Hash-chain ledger (append-only). The unique keys make duplicate ICVs and chain forks impossible.
        $this->create('chain_entries', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('device_id');
            $t->string('environment', 16);
            $t->unsignedBigInteger('icv');
            $t->string('invoice_hash', 64);
            $t->string('previous_invoice_hash', 128);
            $t->unsignedBigInteger('issuance_id');
            $t->dateTime('committed_at');
            $t->unique(['device_id', 'environment', 'icv'], Tables::index('chain_icv_uk'));
            $t->unique(['device_id', 'environment', 'previous_invoice_hash'], Tables::index('chain_pih_uk'));
            $t->unique('issuance_id', Tables::index('chain_issuance_uk'));
            Tables::foreign($t, 'device_id', 'devices', 'chain_device');
            Tables::foreign($t, 'issuance_id', 'invoice_issuances', 'chain_issuance');
        });

        // Immutable XML / QR artifacts; bytes live on a private disk.
        $this->create('invoice_artifacts', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('issuance_id')->nullable();  // null for previews
            $t->unsignedBigInteger('invoice_id');
            $t->string('kind', 32);
            $t->string('disk', 64);
            $t->string('path', 512);
            $t->char('sha256', 64);
            $t->unsignedBigInteger('bytes');
            $t->boolean('encrypted')->default(false);
            $t->string('mime', 64)->default('application/xml');
            $this->createdAt($t);
            $t->index(['issuance_id', 'kind'], Tables::index('art_issuance'));
            $t->index('invoice_id', Tables::index('art_invoice'));
            Tables::foreign($t, 'issuance_id', 'invoice_issuances', 'art_issuance');
            Tables::foreign($t, 'invoice_id', 'invoices', 'art_invoice');
        });
    }

    public function down(): void
    {
        $this->drop('invoice_artifacts', 'chain_entries', 'invoice_issuances', 'invoice_drafts', 'invoice_versions', 'invoices', 'invoice_counters');
    }
};
