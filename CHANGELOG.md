# Changelog

All notable changes are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/)
and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.4.0-alpha.1] - not yet tagged

### Added
- Processing infrastructure (milestone M5):
  - `Zatca::b2c()->issue()`, `Zatca::b2b()->issue()` and `submit()`, returning `IssueResult`. Queue mode
    answers `{"status": true, "message": "invoice queued", "qr": ..., "counter": ...}` without calling ZATCA.
  - Idempotent intake per `external_invoice_id`: replays return the original result, and a different
    payload is refused. The original payload is stored unmodified. Invalid payloads never use a counter.
  - `ChainService`: ICV/PIH allocation under a row lock, with compare-and-set and ledger unique keys.
    Artifact, issuance, ledger entry, counter, submission and outbox commit together; a failure consumes no ICV.
  - Content-addressed, write-once artifact store (optional encryption, SHA-256 checked on every read).
  - Transactional outbox with after-commit dispatch, a relay (`zatca:outbox:relay [--daemon]`), leases,
    backpressure, dead letters and pruning (`zatca:outbox:prune`).
  - Submission runner: claim with a fencing token, then HTTP outside any transaction, then the outcome.
    Reporting and clearance endpoints, cleared XML stored, retries with jittered backoff (B2C compressed to
    the 24 h deadline), per-environment circuit breaker and rate limit. 401/403 stop for manual review.
  - Error engine with fingerprint grouping and redaction; a processing log per status transition;
    lifecycle events `InvoiceIssued`, `InvoiceAccepted`, `InvoiceRejected`, `InvoiceSubmissionFailed`.
  - `ChainVerifier` and `zatca:chain:verify [--deep]` (scheduled daily).
  - Optional HTTP API with load shedding (429/503 + `Retry-After`).
- Tests: an end-to-end processing suite and a multi-process concurrency test (6 processes on one device,
  contiguous ICVs, deep chain verification), passing on MariaDB 10.6. 118 tests passing on Laravel 9-13.
- Verified live on the developer portal (2026-11-02): B2C reported through the queue, B2B cleared.

### Fixed
- MySQL/MariaDB tables are always created with InnoDB. On a server that defaulted to MyISAM, transactions
  and row locks were ignored.
- `LaravelEventDispatcher` resolves the dispatcher at dispatch time, so it works with `Event::fake()`.

### Notes
- Developer portal: its production certificate does not belong to the device key. In the `sandbox`
  environment only, documents are signed with the compliance certificate (warning
  `SANDBOX_SIGNING_CERTIFICATE`). See ARCHITECTURE §2.3.
- ⚠ Not yet run on PostgreSQL or SQL Server locally.

## [0.3.0-alpha.1] - not yet tagged

### Added
- Invoice engine (milestone M4):
  - Separate B2B (`StandardInvoiceGenerator`, `StandardBusinessRules`) and B2C
    (`SimplifiedInvoiceGenerator`, `SimplifiedBusinessRules`) pipelines; a payload for one workflow is
    rejected by the other, never converted.
  - Payload mapper with field-level errors and ZATCA rule IDs; all errors collected in one pass.
  - Exact decimal totals and VAT breakdown (brick/math, half-up, per VAT group). The breakdown is always
    written (avoids the BR-S-01 class of rejections). A missing VAT category is inferred as S only at
    15% (with a warning); 0% without a category is an error.
  - UBL 2.1 writer in ZATCA's sample layout, invoice hashing (C14N), XAdES signing, QR TLV (tags 1-9)
    and an independent `SignatureVerifier`. Every signed document is self-verified before it is returned.
  - Hardened XML parsing (no DTD/entities, no network).
  - Fatoora `POST /compliance/invoices` and `InvoiceSubmissionResult` (acceptance is read from the
    response body, never from the HTTP status alone).
  - Compliance checks during onboarding (`FatooraComplianceCheckRunner`, bound by default): invoice,
    credit note and debit note per registered workflow, on a separate sample chain.
  - Seller address options on `zatca:taxpayer:register`.
- Verified against the ZATCA SDK 238-R3.3.3 samples and live on the developer portal (2026-11-02).
- 102 tests passing locally on Laravel 9, 10, 11, 12 and 13 (brick/math 0.11 to 1.0).

### Changed
- Onboarding now runs ZATCA compliance checks by default. `ZATCA_SANDBOX_SKIP_COMPLIANCE_CHECKS=true`
  still skips them on the developer portal only.
- New dependency: `brick/math` (already installed with Laravel).

## [0.2.0-alpha.1] - not yet tagged

### Added
- Device management (milestone M3):
  - Pure-PHP CSR generator (secp256k1, ZATCA fields, VAT-group rules). Its signed part is byte-identical
    to OpenSSL's output for the configuration used by established ZATCA integrations.
  - Fatoora client for compliance CSID, production CSID and renewal, with ZATCA error codes and a
    retryable/non-retryable classification.
  - Envelope-encrypted credential vault (AES-256-GCM) with master-key rotation and a KMS contract.
  - Onboarding state machine with resume, failure handling and a compliance-check contract.
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
