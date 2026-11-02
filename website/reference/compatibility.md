# Compatibility

Only combinations that pass the automated test suite are listed as tested.

## Laravel and PHP

Every combination below runs the full suite (185 tests) in GitHub Actions, both with the newest and with
the oldest allowed versions of every dependency.

| Laravel | PHP | Result |
|---|---|---|
| 9.52 (final 9.x release) | 8.1, 8.2 | ✅ CI green ⚠ end-of-life |
| 10.x | 8.1 – 8.3 | ✅ CI green ⚠ end-of-life |
| 11.x | 8.2 – 8.4 | ✅ CI green ⚠ end-of-life |
| 12.x | 8.2 – 8.4 | ✅ CI green |
| 13.x | 8.3, 8.4 | ✅ CI green |

Laravel 9 releases before 9.52 are not supported (they lack APIs the package and its tests rely on).

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
| PostgreSQL 16 | ✅ Full suite in CI |
| SQL Server 2019+ | ✅ Supported ⚠ the automated suite has not completed a run yet (CI setup issue with the ODBC driver, not a package error). Install the driver and extensions as in [SQL Server setup](/guide/sql-server) and run the package tests once against your server |

## ZATCA environments

| Environment | Status |
|---|---|
| Developer portal (sandbox) | ✅ Verified live: onboarding, B2C reporting, B2B clearance, corrections, credit and debit notes |
| Simulation | ⚠ Not yet verified |
| Production | ⚠ Not yet verified. Do not use before 1.0 |
