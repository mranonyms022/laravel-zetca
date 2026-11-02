# Testing

## Run the test suite

```bash
composer install
composer test                       # unit + integration, in-memory SQLite
composer test:unit
composer test:integration
php tools/check-core-boundary.php   # src/Core must not depend on Laravel
```

## Against other databases

The integration suite runs unchanged on every supported engine:

```bash
ZATCA_TEST_DB=mysql   DB_HOST=127.0.0.1 DB_DATABASE=zatca_test DB_USERNAME=root DB_PASSWORD=secret vendor/bin/phpunit
ZATCA_TEST_DB=mariadb DB_PORT=3307 ...
ZATCA_TEST_DB=pgsql   DB_USERNAME=postgres ...
ZATCA_TEST_DB=sqlsrv  DB_USERNAME=sa ...
```

The database must exist; tables are recreated for every test.

## Across Laravel versions locally

`tools/local-matrix.sh` copies the package into isolated folders and runs the suite per Laravel version
with the PHP binary you choose:

```bash
COMPOSER_PHAR=/c/composer/composer.phar bash tools/local-matrix.sh /tmp/zatca-matrix \
  "^9.0:^7.0:/c/wamp64/bin/php/php8.1.31/php.exe" \
  "^10.0:^8.0:/c/wamp64/bin/php/php8.2.28/php.exe" \
  "^11.0:^9.0:/c/wamp64/bin/php/php8.2.28/php.exe" \
  "^12.0:^10.0:/c/wamp64/bin/php/php8.4.10/php.exe" \
  "^13.0:^11.0:/c/wamp64/bin/php/php8.4.10/php.exe"
```

Format of each cell: `<illuminate constraint>:<testbench constraint>:<php binary>`.

## Try it in a Laravel application

```bash
composer create-project laravel/laravel zatca-demo
cd zatca-demo
composer config repositories.zatca '{"type":"path","url":"D:/nirbhay/packages/laravel-zatca"}'
composer require codingtriplets/laravel-zatca:@dev
php artisan vendor:publish --tag=zatca-config
php artisan migrate
php artisan tinker --execute="echo Zatca::version();"
```

## Continuous integration

`.github/workflows/ci.yml` runs on every push and nightly:

| Job | Covers |
|---|---|
| Core boundary | `src/Core` has no Laravel dependency |
| Laravel matrix | L9–L13 × supported PHP versions × lowest/stable dependencies (SQLite) |
| Databases | MySQL 8.4, MariaDB 10.11, PostgreSQL 16, SQL Server 2022 |
| Laravel 14 preview | `14.x-dev`, allowed to fail until Laravel 14 is released |

## Writing tests (compatibility rules)

The same tests run on PHPUnit 9.5 through 12:

- Name test methods `test_*`; do not use `@test` or `#[Test]`.
- Avoid data providers, or declare them with **both** `@dataProvider` and `#[DataProvider]` and make them `static`.
- Integration tests extend `Tests\Integration\IntegrationTestCase` (real database, no wrapping transaction).
- Mock only the HTTP transport to ZATCA; all domain code and the database run for real.
