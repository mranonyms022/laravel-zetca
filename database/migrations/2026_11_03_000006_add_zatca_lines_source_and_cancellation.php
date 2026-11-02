<?php

declare(strict_types=1);

use CodingTriplets\Zatca\Laravel\Database\Schema\Tables;
use CodingTriplets\Zatca\Laravel\Database\Schema\ZatcaMigration;
use Illuminate\Database\Schema\Blueprint;

/**
 * M6.1: invoice lines + VAT breakdown, link to the host application's own record ("source"),
 * cancellation tracking, and external references for companies / stores (host app IDs).
 */
return new class extends ZatcaMigration {
    public function up(): void
    {
        // Calculated lines exactly as written in the signed XML, one set per issuance (append-only).
        $this->create('invoice_lines', function (Blueprint $t): void {
            $t->id();
            $this->tenant($t);
            $t->unsignedBigInteger('invoice_id');
            $t->unsignedBigInteger('issuance_id');
            $t->unsignedInteger('line_no');
            $t->string('name', 1000);
            $t->string('item_code', 191)->nullable();
            $t->decimal('quantity', 24, 6);
            $t->string('unit_code', 16);
            $t->decimal('unit_price', 24, 6);           // gross unit price
            $t->decimal('unit_discount', 24, 6)->default(0);   // per-unit price discount (BT-147)
            $this->money($t, 'line_discount');          // Σ line allowances (BG-27)
            $this->money($t, 'net_amount');             // BT-131
            $t->string('vat_category', 2);
            $t->decimal('vat_rate', 5, 2);
            $t->string('exemption_reason_code', 32)->nullable();
            $this->money($t, 'vat_amount');
            $this->money($t, 'amount_incl_vat');
            $t->json('discounts')->nullable();          // line allowances with reason / percent
            $this->createdAt($t);
            $t->index(['invoice_id', 'issuance_id'], Tables::index('line_invoice'));
            $t->index('issuance_id', Tables::index('line_issuance'));
            $t->index(['tenant_id', 'item_code'], Tables::index('line_item'));
            Tables::foreign($t, 'invoice_id', 'invoices', 'line_invoice');
            Tables::foreign($t, 'issuance_id', 'invoice_issuances', 'line_issuance');
        });

        $this->create('invoice_vat_breakdown', function (Blueprint $t): void {
            $t->id();
            $this->tenant($t);
            $t->unsignedBigInteger('invoice_id');
            $t->unsignedBigInteger('issuance_id');
            $t->string('vat_category', 2);
            $t->decimal('vat_rate', 5, 2);
            $t->string('exemption_reason_code', 32)->nullable();
            $this->money($t, 'taxable_amount');
            $this->money($t, 'vat_amount');
            $this->createdAt($t);
            $t->index(['invoice_id', 'issuance_id'], Tables::index('vatb_invoice'));
            $t->index(['tenant_id', 'vat_category', 'vat_rate'], Tables::index('vatb_category'));
            Tables::foreign($t, 'invoice_id', 'invoices', 'vatb_invoice');
            Tables::foreign($t, 'issuance_id', 'invoice_issuances', 'vatb_issuance');
        });

        $this->schema()->table(Tables::name('invoices'), function (Blueprint $t): void {
            $t->string('source_type', 191)->nullable();
            $t->string('source_id', 64)->nullable();
            $this->money($t, 'credited_amount');      // Σ credit notes (not rejected/cancelled/abandoned)
            $this->money($t, 'debited_amount');       // Σ debit notes
            $t->unsignedBigInteger('cancellation_invoice_id')->nullable();   // full credit note from cancel()
            $t->dateTime('closed_at')->nullable();    // discarded / abandoned
            $t->index(['source_type', 'source_id'], Tables::index('inv_source'));
        });

        foreach (['companies' => 'co_external', 'branches' => 'br_external'] as $table => $index) {
            $this->schema()->table(Tables::name($table), function (Blueprint $t) use ($index): void {
                // The host application's own ID. Unique per tenant (companies) / company (stores), enforced in code
                // because SQL Server does not allow several NULLs in a unique index.
                $t->string('external_ref', 191)->nullable();
                $t->index('external_ref', Tables::index($index));
            });
        }
    }

    public function down(): void
    {
        foreach (['companies' => 'co_external', 'branches' => 'br_external'] as $table => $index) {
            $this->schema()->table(Tables::name($table), function (Blueprint $t) use ($index): void {
                $t->dropIndex(Tables::index($index));
                $t->dropColumn('external_ref');
            });
        }
        $this->schema()->table(Tables::name('invoices'), function (Blueprint $t): void {
            $t->dropIndex(Tables::index('inv_source'));
            $t->dropColumn(['source_type', 'source_id', 'credited_amount', 'debited_amount', 'cancellation_invoice_id', 'closed_at']);
        });
        $this->drop('invoice_vat_breakdown', 'invoice_lines');
    }
};
