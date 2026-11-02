# Changelog

All notable changes are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/)
and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

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
