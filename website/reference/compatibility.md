# Compatibility

Only combinations that pass the automated test suite are listed as tested.

## Laravel and PHP

| Laravel | PHP tested | Result |
|---|---|---|
| 9.x | 8.1 | ✅ 135 tests pass |
| 10.x | 8.2 | ✅ 135 tests pass |
| 11.x | 8.2 | ✅ 135 tests pass |
| 12.x | 8.4 | ✅ 135 tests pass |
| 13.x | 8.4 | ✅ 135 tests pass |

## Databases

| Database | Status |
|---|---|
| SQLite | ✅ Full suite (development and tests only) |
| MariaDB 10.x / 11.x | ✅ Integration suite (64 tests) on Laravel 9 and 13, including concurrent issuance from 6 processes |
| MySQL 8 | ⚠ Expected to work (same code path as MariaDB); not yet run in CI |
| PostgreSQL 13+ | ⚠ Supported by design; not yet tested |
| SQL Server 2019+ | ⚠ Supported by design; not yet tested |

## ZATCA environments

| Environment | Status |
|---|---|
| Developer portal (sandbox) | ✅ Verified live: onboarding, B2C reporting, B2B clearance, corrections, credit and debit notes |
| Simulation | ⚠ Not yet verified |
| Production | ⚠ Not yet verified. Do not use before 1.0 |
