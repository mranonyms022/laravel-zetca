<?php

declare(strict_types=1);

use CodingTriplets\Zatca\Laravel\Database\Schema\Tables;
use CodingTriplets\Zatca\Laravel\Database\Schema\ZatcaMigration;
use Illuminate\Database\Schema\Blueprint;

return new class extends ZatcaMigration {
    public function up(): void
    {
        $common = function (Blueprint $t, string $short): void {
            $t->id();
            $t->unsignedBigInteger('issuance_id');
            $t->unsignedBigInteger('invoice_id');
            $t->string('status', 32);
            $t->unsignedInteger('attempts')->default(0);
            $t->string('claim_token', 64)->nullable();        // fencing token for the outcome write
            $t->dateTime('claimed_until')->nullable();
            $t->dateTime('first_attempt_at')->nullable();
            $t->dateTime('completed_at')->nullable();
            $t->boolean('manual')->default(false);
            $t->unsignedBigInteger('requested_by')->nullable();
            $t->unique('issuance_id', Tables::index($short . '_issuance_uk'));
            $t->index(['status', 'claimed_until'], Tables::index($short . '_status'));
            $t->index('invoice_id', Tables::index($short . '_invoice'));
        };

        $this->create('b2b_clearance_submissions', function (Blueprint $t) use ($common): void {
            $common($t, 'b2bsub');
            $t->string('clearance_status_raw', 64)->nullable();
            $t->unsignedBigInteger('cleared_artifact_id')->nullable();
            $this->datetimes($t);
            Tables::foreign($t, 'issuance_id', 'invoice_issuances', 'b2bsub_issuance');
            Tables::foreign($t, 'invoice_id', 'invoices', 'b2bsub_invoice');
            Tables::foreign($t, 'cleared_artifact_id', 'invoice_artifacts', 'b2bsub_cleared');
        });

        $this->create('b2c_reporting_submissions', function (Blueprint $t) use ($common): void {
            $common($t, 'b2csub');
            $t->string('reporting_status_raw', 64)->nullable();
            $t->dateTime('deadline_at');
            $t->boolean('deadline_breached')->default(false);
            $this->datetimes($t);
            $t->index(['status', 'deadline_at'], Tables::index('b2csub_deadline'));
            Tables::foreign($t, 'issuance_id', 'invoice_issuances', 'b2csub_issuance');
            Tables::foreign($t, 'invoice_id', 'invoices', 'b2csub_invoice');
        });

        // API log: every HTTP exchange (append-only once completed).
        $this->create('submission_attempts', function (Blueprint $t): void {
            $t->id();
            $t->string('workflow', 3);
            $t->unsignedBigInteger('submission_id');             // b2b_clearance_submissions or b2c_reporting_submissions
            $t->unsignedBigInteger('invoice_id');
            $t->unsignedBigInteger('issuance_id');
            $t->unsignedInteger('attempt_number');
            $t->string('endpoint', 255);
            $t->string('environment', 16);
            $t->json('request_headers')->nullable();             // sanitized
            $t->json('request_body')->nullable();                // without the base64 XML (artifact referenced)
            $t->unsignedBigInteger('request_artifact_id')->nullable();
            $t->json('response_body')->nullable();
            $t->unsignedSmallInteger('http_status')->nullable();
            $t->string('zatca_status', 64)->nullable();
            $t->string('outcome', 32)->nullable();
            $t->string('error_code', 64)->nullable();
            $t->text('error_message')->nullable();
            $t->dateTime('started_at');
            $t->dateTime('completed_at')->nullable();
            $t->unsignedInteger('duration_ms')->nullable();
            $t->string('triggered_by', 64)->default('system');
            $t->unsignedBigInteger('bulk_operation_id')->nullable();
            $t->string('idempotency_key', 191);
            $this->createdAt($t);
            $t->unique(['workflow', 'submission_id', 'attempt_number'], Tables::index('att_uk'));
            $t->index(['outcome', 'started_at'], Tables::index('att_outcome'));
            $t->index('started_at', Tables::index('att_started'));
            $t->index('invoice_id', Tables::index('att_invoice'));
            Tables::foreign($t, 'invoice_id', 'invoices', 'att_invoice');
            Tables::foreign($t, 'issuance_id', 'invoice_issuances', 'att_issuance');
        });
    }

    public function down(): void
    {
        $this->drop('submission_attempts', 'b2c_reporting_submissions', 'b2b_clearance_submissions');
    }
};
