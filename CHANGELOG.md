# Changelog

All notable changes are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/)
and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.5.0-alpha.1] - not yet tagged

### Added
- B2B and B2C workflows (milestone M6), through `Zatca::invoices()` and `php artisan zatca:invoice`:
  - **Retry** (operation A): resends the byte-identical signed document after transient failures, an
    unknown outcome or exhausted attempts. Refused for accepted or rejected documents.
  - **Correct & reprocess** (operation B): a new JSON version (reason and changed fields recorded, original
    kept) on the same invoice record. A rejected document is replaced by a new issuance (new UUID, next ICV,
    PIH = chain head); the rejected one stays in the chain as `superseded` and its errors are resolved.
    Invalid corrections save nothing. Pre-issuance (validation failed) invoices can be corrected too.
  - **Reissue unchanged** for rejections caused by the package or a certificate.
  - **Four-eyes approval** (`correction.four_eyes`): the approver must differ from the corrector.
  - **Credit and debit notes** (operation C): only for accepted invoices, same workflow, linked to the
    original (`billing_reference_invoice_id`, `BillingReference`), mandatory reason.
  - Rejected-invoice policies per workflow (`ClearanceRejectionPolicy`, `ReportingRejectionPolicy`):
    B2C keeps the invoice number and issue date, B2B uses the reissue time ⚠ (configurable).
  - **303 clearance switched off**: the same signed standard invoice is reported instead
    (`b2b.on_clearance_deactivated = report|manual_review`) ⚠.
  - **Reporting deadline monitor** (`zatca:deadlines:check`, every 10 minutes): at-risk alerts per threshold,
    breach flag and CRITICAL error.
  - **Reconciler** (`zatca:reconcile`, every 15 minutes): re-queues submissions and B2B generations whose
    outbox message was lost.
  - Events: `InvoiceReissued`, `CustomerCopyReissued`, `InvoiceReportingDeadlineAtRisk`,
    `InvoiceReportingDeadlineBreached`, `ClearanceDeactivated`.
- 135 tests passing on Laravel 9-13; integration suite passing on MariaDB 10.6.
- Verified live (2026-11-02): corrected B2C invoice reported after a (simulated) rejection, with the PIH
  chained to the rejected document; B2C credit note reported; B2B invoice and debit note cleared.

### Fixed
- An unexpected exception during a submission no longer leaves the claim in flight until the lease expires;
  it is recorded as an unknown outcome and the identical document is resent.
- A replayed `issue()` completes an invoice whose first call stopped between intake and issuance.

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
