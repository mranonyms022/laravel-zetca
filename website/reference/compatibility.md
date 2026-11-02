# Compatibility

Only combinations that pass the automated test suite are listed as tested.

## Laravel and PHP

| Laravel | PHP | Result |
|---|---|---|
| 9.x | 8.1, 8.2 | ✅ 173 tests pass (PHP 8.1, local) ⚠ end-of-life |
| 10.x | 8.1 – 8.3 | ✅ 173 tests pass (PHP 8.2, local) ⚠ end-of-life |
| 11.x | 8.2 – 8.4 | ✅ 173 tests pass (PHP 8.2, local) ⚠ end-of-life |
| 12.x | 8.2 – 8.4 | ✅ 173 tests pass; CI green on PHP 8.2, 8.3, 8.4 (lowest and stable dependencies) |
| 13.x | 8.3, 8.4 | ✅ 173 tests pass; CI green on PHP 8.3, 8.4 (lowest and stable dependencies) |

::: warning Laravel 9, 10 and 11 are end-of-life
They no longer receive security fixes, and every release has open security advisories. Composer 2.9+
refuses to install them unless you set `composer config audit.block-insecure false`. The package works
on them, but use Laravel 12 or 13 for new projects and plan the upgrade for existing ones.
:::

## Databases

| Database | Status |
|---|---|
| SQLite | ✅ Full suite (development and tests only) |
| MariaDB 10.x / 11.x | ✅ Full integration suite, locally and in CI, including concurrent issuance from 6 processes |
| MySQL 8 | ✅ Full suite on MySQL 8.3 (local) and 8.4 (CI) |
| PostgreSQL 13+ | ❌ The CI run on PostgreSQL 16 fails; being fixed. Do not use yet |
| SQL Server 2019+ | ❌ The CI run on SQL Server 2022 fails; being fixed. Do not use yet |

## ZATCA environments

| Environment | Status |
|---|---|
| Developer portal (sandbox) | ✅ Verified live: onboarding, B2C reporting, B2B clearance, corrections, credit and debit notes |
| Simulation | ⚠ Not yet verified |
| Production | ⚠ Not yet verified. Do not use before 1.0 |
