<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Tests\Integration\Tenancy;

use CodingTriplets\Zatca\Core\Contracts\Tenancy\TenantResolver;
use CodingTriplets\Zatca\Laravel\Database\Models\Company;
use CodingTriplets\Zatca\Tests\Integration\IntegrationTestCase;

final class TenantScopeTest extends IntegrationTestCase
{
    private ?string $tenant = null;

    /** @param \Illuminate\Foundation\Application $app */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('zatca.tenancy.mode', 'column');

        $app->instance(TenantResolver::class, new class ($this) implements TenantResolver {
            public function __construct(private readonly TenantScopeTest $test)
            {
            }

            public function current(): ?string
            {
                return $this->test->currentTenant();
            }
        });
    }

    public function currentTenant(): ?string
    {
        return $this->tenant;
    }

    public function test_rows_are_stamped_and_isolated_per_tenant(): void
    {
        $this->tenant = 'tenant-a';
        Company::create(['name_en' => 'A Co']);

        $this->tenant = 'tenant-b';
        Company::create(['name_en' => 'B Co']);

        self::assertSame(['B Co'], Company::query()->pluck('name_en')->all());

        $this->tenant = 'tenant-a';
        self::assertSame(['A Co'], Company::query()->pluck('name_en')->all());
        self::assertSame('tenant-a', Company::query()->firstOrFail()->tenant_id);

        $this->tenant = null; // no active tenant (e.g. system jobs) sees all rows
        self::assertSame(2, Company::query()->count());
    }
}
