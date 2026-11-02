# Changelog

All notable changes are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/)
and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.2.0-alpha.1] - not yet tagged

### Added
- Device management (milestone M3):
  - Pure-PHP CSR generator (secp256k1, ZATCA fields, VAT-group rules). Its signed part is byte-identical
    to OpenSSL's output for the configuration used by established ZATCA integrations.
  - Fatoora client for compliance CSID, production CSID and renewal, with ZATCA error codes and a
    retryable/non-retryable classification.
  - Envelope-encrypted credential vault (AES-256-GCM) with master-key rotation and a KMS contract.
  - Onboarding state machine with resume, failure handling and a compliance-check contract (implemented in M4).
  - Certificate renewal, daily expiry monitoring, and expiry warnings/events.
  - Hash-chained, append-only audit log with `zatca:audit:verify`.
  - Commands: `zatca:taxpayer:register`, `zatca:device:register`, `zatca:device:onboard`,
    `zatca:device:renew`, `zatca:devices`, `zatca:devices:check-expiry`, `zatca:audit:verify`.
  - Opt-in live test suite against ZATCA's developer portal (`ZATCA_LIVE_SANDBOX=1`).
- Verified live on 2026-11-02 against the developer portal: compliance CSID, production CSID and renewal.
- 67 tests passing locally on Laravel 9, 10, 11, 12 and 13.

### Upgrade notes
- If you published `config/zatca.php` before, re-publish it (`--force`) or copy the new keys
  (`security.previous_encryption_keys`, `csr.serial_attribute`, `devices.*`, `b2c.*`, `api`, `performance`).
  Laravel does not merge nested keys of a published config.


## [0.1.0-alpha.1] - not yet tagged

### Added
- Package foundation (milestone M2): Composer package for Laravel 9–13 (PHP 8.1+).
- Framework-independent core: domain enums, value objects (VAT number, TIN, EGS serial, invoice-type
  flags, subtype flags, invoice hash, buyer identification), exceptions and contracts.
- Database schema (28 tables) with configurable prefix and connection; database-level protection
  against duplicate ICVs, hash-chain forks and duplicate intake.
- Eloquent models with append-only protection for regulatory history and column-mode tenant scoping.
- Laravel integration: service provider, configuration validation, transaction manager with safe
  retries, advisory locks, after-commit events.
- Test suite (33 tests) passing locally on Laravel 9, 10, 11, 12 and 13; GitHub Actions matrix.
- Documentation: installation, configuration, user guide, testing, publishing, architecture.

### Not yet included
- Device onboarding, invoice issuing/submission, management dashboard (see docs/user-guide.md#roadmap).
