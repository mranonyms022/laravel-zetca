# Installation

> Package status: **0.1.0-dev (milestone M2 – foundation)**. Installing it today gives you the database
> schema, configuration and core domain types. Device onboarding, invoice issuing and the dashboard
> arrive in later milestones (see the [User Guide](user-guide.md#roadmap)).

## Requirements

| Item | Requirement |
|---|---|
| PHP | 8.1+ (per your Laravel version: L9 8.1–8.2, L10 8.1–8.3, L11 8.2–8.4, L12 8.2–8.4, L13 8.3–8.4) |
| PHP extensions | `openssl`, `dom`, `libxml`, `mbstring`, `json`, and the PDO driver for your database |
| Laravel | 9.x, 10.x, 11.x, 12.x or 13.x |
| Database | MySQL 8.0+, MariaDB 10.6+, PostgreSQL 13+, SQL Server 2019+ (SQLite for development/tests only) |
| Cache | A lock-capable store (Redis recommended) when you run more than one queue worker |
| Storage | A **private** filesystem disk; a shared disk (e.g. S3) when you run more than one app server |

## 1. Require the package

From Packagist (once published):

```bash
composer require codingtriplets/laravel-zatca
```

From a Git repository before Packagist publication (private or public repo):

```json
// composer.json of your Laravel app
"repositories": [
    { "type": "vcs", "url": "https://github.com/<your-org>/laravel-zatca" }
]
```

```bash
composer require codingtriplets/laravel-zatca:dev-main
```

From a local folder (for trying it out):

```json
"repositories": [
    { "type": "path", "url": "D:/nirbhay/packages/laravel-zatca", "options": { "symlink": true } }
]
```

```bash
composer require codingtriplets/laravel-zatca:@dev
```

The service provider and the `Zatca` facade are registered automatically (package discovery).

## 2. Publish the configuration

```bash
php artisan vendor:publish --tag=zatca-config
```

This creates `config/zatca.php`. See [Configuration](configuration.md).

## 3. Set environment variables

```dotenv
ZATCA_ENVIRONMENT=sandbox            # sandbox | simulation | production
ZATCA_ENCRYPTION_KEY=base64:...      # dedicated key for private keys / CSID secrets
ZATCA_ARTIFACT_DISK=local            # private disk for signed XML
```

Generate an encryption key:

```bash
php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
```

Keep this key safe and backed up: losing it makes stored private keys and CSID secrets unrecoverable,
and devices would have to be onboarded again.

## 4. Run the migrations

```bash
php artisan migrate
```

The package creates 28 tables prefixed with `zatca_` (configurable). To copy the migration files into
your application instead (e.g. to review them):

```bash
php artisan vendor:publish --tag=zatca-migrations
```

## 5. Verify

```bash
php artisan tinker --execute="echo Zatca::version(), ' ', Zatca::defaultEnvironment()->value;"
# 0.1.0-dev sandbox
```

## Upgrading

Until 1.0.0, minor versions may change the schema. Always read the [Changelog](../CHANGELOG.md) and run
`php artisan migrate` after updating.
