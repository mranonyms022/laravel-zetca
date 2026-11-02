# codingtriplets/laravel-zatca

ZATCA Phase 2 (Fatoora) e-invoicing engine and management console for Laravel, by CodingTriplets Technologies.

> **Status: under development (milestone M3 done: device onboarding and renewal).** Not yet usable for invoicing (M4–M6).
> Architecture and design decisions: [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md), which is the source of truth.

## Documentation

| Guide | |
|---|---|
| [Installation](docs/installation.md) | Requirements, install options (Packagist, VCS, local path), first setup |
| [Configuration](docs/configuration.md) | Every option in `config/zatca.php` |
| [User Guide](docs/user-guide.md) | Concepts, what works today, data model, validation helpers, roadmap |
| [Testing](docs/testing.md) | Test suite, other databases, Laravel version matrix, CI |
| [Publishing](docs/publishing.md) | Releasing on Packagist or privately |
| [Architecture](docs/ARCHITECTURE.md) | Design source of truth |
| [ZATCA references](docs/zatca-references.md) | Official documents the code is checked against |
| [Changelog](CHANGELOG.md) | |

## What it will do

- Separate **B2B** (standard invoice → clearance) and **B2C** (simplified invoice → reporting) pipelines.
- Database-first processing: every invoice is persisted before any ZATCA call, and dispatched through a transactional outbox.
- Device (EGS) onboarding and renewal: CSR, compliance CSID, compliance checks, production CSID, certificate history.
- Atomic per-device ICV allocation and PIH hash chaining, with database-level guarantees against duplicates and forks.
- Human-in-the-loop correction of rejected invoices: one internal record, versioned JSON, and a new legal issuance (new UUID/ICV) per correction.
- Built-in Blade admin console at `/admin/zatca`.

## Supported versions

Compatibility is claimed only for combinations that pass CI ([`.github/workflows/ci.yml`](.github/workflows/ci.yml)).

| Laravel | PHP | Status |
|---|---|---|
| 9.x | 8.1 – 8.2 | Targeted (framework EOL: no upstream security fixes) |
| 10.x | 8.1 – 8.3 | Targeted (framework EOL) |
| 11.x | 8.2 – 8.4 | Targeted (framework EOL) |
| 12.x | 8.2 – 8.4 | Targeted |
| 13.x | 8.3 – 8.4 | Targeted |
| 14.x | — | Not released; added once stable and green in CI |

Supported databases: MySQL 8.0+, MariaDB 10.6+, PostgreSQL 13+, SQL Server 2019+. SQLite is for development and tests only.

## Installation (development)

```bash
composer require codingtriplets/laravel-zatca
php artisan vendor:publish --tag=zatca-config
php artisan migrate
```

Set a dedicated key for credential encryption:

```dotenv
ZATCA_ENVIRONMENT=sandbox
ZATCA_ENCRYPTION_KEY=base64:...   # 32 random bytes, base64
ZATCA_ARTIFACT_DISK=local         # private disk; use a shared disk (e.g. s3) on multi-server setups
```

## Testing

```bash
composer test                    # unit + integration (in-memory SQLite)
php tools/check-core-boundary.php
ZATCA_TEST_DB=pgsql DB_PASSWORD=secret vendor/bin/phpunit   # against PostgreSQL / mysql / mariadb / sqlsrv
```
