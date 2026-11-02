<?php

declare(strict_types=1);

use CodingTriplets\Zatca\Laravel\Database\Schema\Tables;
use CodingTriplets\Zatca\Laravel\Database\Schema\ZatcaMigration;
use Illuminate\Database\Schema\Blueprint;

/** Stores can be disabled from the dashboard (companies already have a status column). */
return new class extends ZatcaMigration {
    public function up(): void
    {
        $this->schema()->table(Tables::name('branches'), function (Blueprint $t): void {
            $t->string('status', 32)->default('active');
        });
    }

    public function down(): void
    {
        $this->schema()->table(Tables::name('branches'), function (Blueprint $t): void {
            $t->dropColumn('status');
        });
    }
};
