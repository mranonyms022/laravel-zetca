<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Tests\Integration\Database;

use CodingTriplets\Zatca\Laravel\Database\Models\Invoice;
use CodingTriplets\Zatca\Tests\Integration\IntegrationTestCase;
use Illuminate\Support\Facades\Schema;

final class CustomPrefixTest extends IntegrationTestCase
{
    /** @param \Illuminate\Foundation\Application $app */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('zatca.database.table_prefix', 'einv_');
    }

    public function test_tables_and_models_follow_the_configured_prefix(): void
    {
        self::assertTrue(Schema::hasTable('einv_invoices'));
        self::assertFalse(Schema::hasTable('zatca_invoices'));
        self::assertSame('einv_invoices', (new Invoice())->getTable());
    }
}
