<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Tests\Integration;

use CodingTriplets\Zatca\Laravel\ZatcaServiceProvider;
use Orchestra\Testbench\TestCase;

/**
 * Each test starts from a freshly migrated database without a wrapping transaction,
 * so commit / rollback / after-commit behaviour is exercised for real.
 *
 * Default: in-memory SQLite. CI sets ZATCA_TEST_DB=mysql|mariadb|pgsql|sqlsrv (+ DB_HOST/PORT/
 * DATABASE/USERNAME/PASSWORD) to run the identical suite on the other supported engines.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan(self::driver() === 'sqlite' ? 'migrate' : 'migrate:fresh')->run();
    }

    /** @param \Illuminate\Foundation\Application $app */
    protected function getPackageProviders($app): array
    {
        return [ZatcaServiceProvider::class];
    }

    /** @param \Illuminate\Foundation\Application $app */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', self::connectionConfig());
    }

    public static function driver(): string
    {
        $driver = getenv('ZATCA_TEST_DB') ?: 'sqlite';

        return $driver === 'mariadb' ? 'mysql' : $driver;
    }

    /** @return array<string, mixed> */
    public static function connectionConfig(): array
    {
        $driver = self::driver();

        if ($driver === 'sqlite') {
            return ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true];
        }

        $defaults = ['mysql' => 3306, 'pgsql' => 5432, 'sqlsrv' => 1433];

        return array_filter([
            'driver' => $driver,
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('DB_PORT') ?: $defaults[$driver] ?? 0),
            'database' => getenv('DB_DATABASE') ?: 'zatca_test',
            'username' => getenv('DB_USERNAME') ?: 'root',
            'password' => getenv('DB_PASSWORD') ?: '',
            'charset' => $driver === 'mysql' ? 'utf8mb4' : 'utf8',
            'collation' => $driver === 'mysql' ? 'utf8mb4_unicode_ci' : null,
            'prefix' => '',
            'trust_server_certificate' => $driver === 'sqlsrv' ? true : null,
        ], static fn ($v) => $v !== null);
    }
}
