# Installation

## 1. Require the package

```bash
composer require codingtriplets/laravel-zatca
```

Until the package is on Packagist, install it from GitHub:

```json
// composer.json of your application
"repositories": [
    { "type": "vcs", "url": "https://github.com/mranonyms022/laravel-zetca" }
]
```

```bash
composer require codingtriplets/laravel-zatca:^0.7.1
```

The service provider and the `Zatca` facade are registered automatically.

## 2. Publish the configuration

```bash
php artisan vendor:publish --tag=zatca-config
```

This creates `config/zatca.php`. Every option is described in the [configuration reference](/reference/configuration).

## 3. Environment variables

```dotenv
ZATCA_ENVIRONMENT=sandbox              # sandbox | simulation | production
ZATCA_ENCRYPTION_KEY=base64:...        # protects private keys and CSID secrets
ZATCA_ARTIFACT_DISK=local              # private disk for signed XML (s3 with several servers)
ZATCA_B2C_REPORTING_MODE=queue         # queue (fast, recommended) | sync
```

Generate the encryption key once:

```bash
php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
```

::: danger Back up ZATCA_ENCRYPTION_KEY
Device private keys and ZATCA secrets are encrypted with this key. If you lose it, every device must be
onboarded again. Store it in your secrets manager, not only in `.env`.
:::

If PHP cannot verify HTTPS certificates (common on Windows/WAMP), point it to a CA bundle instead of
disabling verification:

```dotenv
ZATCA_CA_BUNDLE=/path/to/cacert.pem
```

## 4. Run the migrations

```bash
php artisan migrate
```

::: tip SQL Server
Install Microsoft ODBC Driver 18 and the `sqlsrv` / `pdo_sqlsrv` PHP extensions first, see
[SQL Server setup](./sql-server).
:::

The package creates its tables with the prefix `zatca_` (configurable with `ZATCA_TABLE_PREFIX`). To
review the migration files first:

```bash
php artisan vendor:publish --tag=zatca-migrations
```

## 5. Start the scheduler and workers

The package registers its scheduled tasks automatically (outbox relay, deadline checks, chain
verification, certificate expiry). Make sure the Laravel scheduler runs:

```text
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

Start queue workers for the ZATCA queues. See [Queues, workers and scheduler](./workers) for the
recommended setup.

## 6. Check the installation

```bash
php artisan tinker --execute="echo Zatca::version(), ' ', Zatca::defaultEnvironment()->value;"
```

## Upgrading

Before 1.0, minor versions can change the database schema. Read the changelog and run
`php artisan migrate` after every update.
