# laravel-zatca — Technical documentation

**Audience:** maintainers and contributors. **Describes:** the code as built at 0.5.0-dev (milestones
M1–M6). **Design source:** [ARCHITECTURE.md](ARCHITECTURE.md) explains *why*; this document explains
*what exists and how it works*. Where the two differ, §12 lists it.

---

## 1. Overview

```
            ┌──────────────── Laravel layer (src/Laravel) ─────────────────┐
 HTTP API ─▶│ InvoiceApiController ─▶ InvoiceIssuer (b2c | b2b)            │
 Facade   ─▶│   InvoiceIntakeService (T1) ─▶ InvoiceProcessor ─▶ ChainService (T2)
 Artisan  ─▶│   InvoiceOperations (retry / correct / reissue / notes)      │
            │   OutboxRecorder ─▶ OutboxDispatcher ─▶ OutboxJob ─▶ Handlers│
            │   SubmissionRunner (T3 claim → HTTP → T4 outcome)            │
            │   DeadlineMonitor, Reconciler, ChainVerifier, ErrorRecorder  │
            └───────────────────────┬──────────────────────────────────────┘
                                    │ uses
            ┌──────────────── Core layer (src/Core) ───────────────────────┐
            │ Validation (payload mapper + business rules)                  │
            │ Calculation (brick/math decimals)  UBL writer  Hashing        │
            │ XAdES signer  QR TLV  Fatoora HTTP client  Crypto (CSR/keys)  │
            │ Domain enums / value objects / events / contracts             │
            └──────────────────────────────────────────────────────────────┘
```

**Core** has no Laravel dependency (enforced in CI by `tools/check-core-boundary.php`). It is pure
document logic: payload → validated DTO → UBL XML → hash → signature → QR, plus the Fatoora API client.
**Laravel** adds persistence, transactions, queues, scheduling, HTTP and commands.

### Dependencies

| Package | Use |
|---|---|
| `brick/math` | Exact decimal arithmetic for all amounts |
| `phpseclib/phpseclib` v3 | secp256k1 keys, CSR with ZATCA extensions, X.509 parsing |
| `ext-openssl` | ECDSA signing on the hot path |
| `guzzlehttp/guzzle` | Fatoora HTTP client |
| `opis/json-schema` | JSON payload schema checks |
| `illuminate/*` 9–13, `nesbot/carbon` 2/3 | Framework integration |

---

## 2. Folder map

| Path | Content |
|---|---|
| `src/Core/Domain` | Enums (statuses, workflow, document types, error categories), value objects (`InvoiceHash`, `Environment`), exceptions |
| `src/Core/Contracts` | Interfaces the Laravel layer implements: persistence, storage, locks, events, tenancy, KMS |
| `src/Core/Crypto` | `EcPrivateKey`, `X509Certificate`, DER helpers |
| `src/Core/Device` | CSR builder, EGS serial, compliance sample generation |
| `src/Core/Fatoora` | `FatooraClient` (compliance, production CSID, reporting, clearance), `InvoiceSubmissionResult` |
| `src/Core/Invoice/Shared` | `Validation`, `Calculation`, `DTOs`, `Xml` (`UblInvoiceWriter`, `SecureXml`, `SignatureTemplate`), `Hashing`, `Signing` (`XadesSigner`, `SignatureVerifier`), `QRCode`, `Chain`, `Correction` |
| `src/Core/Invoice/B2B`, `B2C` | Workflow-specific generator, validator rules and rejection policy |
| `src/Laravel/Invoices` | Intake, issuer, chain service, processor, operations, results, policies |
| `src/Laravel/Processing` | Outbox, submission runner/gateway, retry policy, circuit breaker, monitors, error recorder, logger |
| `src/Laravel/Devices` | Onboarding, renewal, expiry monitor, credential store |
| `src/Laravel/Database` | Models, `ZatcaMigration` schema helpers (`Tables`, InnoDB forcing) |
| `src/Laravel/Storage` | `FilesystemArtifactStore` (content-addressed, SHA-256 verified) |
| `src/Laravel/Http` | API controller, `ShedLoad` middleware |
| `src/Laravel/Console` | 13 Artisan commands |
| `database/migrations` | 5 migrations, 28 tables |
| `tests/Unit`, `tests/Integration` | PHPUnit + Orchestra Testbench; `FakeZatca` HTTP double |

---

## 3. Data model

```
companies 1─* vat_registrations 1─* branches
                     │                 │
                     └──────1─* devices *┘
                                 │ 1─* device_keys, certificates, device_onboardings, device_renewals
                                 │ 1─1 invoice_counters (per environment: last_icv, last_invoice_hash, lock_version)
                                 │ 1─* chain_entries   UK(device, env, icv), UK(device, env, previous_invoice_hash)
                                 │
invoices ─────────────────────────┘ (device_id)
  │ 1─* invoice_versions         append-only; v1 = original JSON
  │ 1─* invoice_issuances        uuid, icv, pih, hash, qr, status, supersedes_issuance_id
  │        1─* invoice_artifacts signed_xml / cleared_xml (disk, path, sha256)
  │        1─1 b2c_reporting_submissions | b2b_clearance_submissions
  │                 1─* submission_attempts
  │ 1─* errors, warnings, processing_logs
  └ billing_reference_invoice_id → invoices (credit/debit notes)
outbox, error_groups, audit_logs, settings, stats_daily, bulk_operations(_items), invoice_drafts
```

**Conventions** (`ZatcaMigration`): prefix from config; InnoDB forced on MySQL/MariaDB; `tenant_id`
string column (`''` = no tenant) on tenant-scoped tables; foreign keys optional
(`database.foreign_keys`); append-only models throw `AppendOnlyViolation` on update/delete.

**Key uniqueness**

| Constraint | Guarantees |
|---|---|
| `invoices UK(company_id, external_invoice_id)` | Idempotent intake |
| `chain_entries UK(device_id, environment, icv)` | No duplicate ICV |
| `chain_entries UK(device_id, environment, previous_invoice_hash)` | No chain fork |
| `invoice_issuances UK(uuid)`, `UK(device_id, environment, icv)` | No reused UUID or ICV |
| `b2c_reporting_submissions` / `b2b_clearance_submissions UK(issuance_id)` | One submission per document |
| `outbox UK(idempotency_key)` | No duplicate outbox message |

---

## 4. Request flows and transactions

No HTTP call ever happens inside a database transaction.

### 4.1 Intake (T1) — `InvoiceIntakeService`

1. Resolve the device (ID or UUID) and its company/VAT registration; check `canIssue()` and that the
   device is registered for the workflow → else `DeviceUnavailable`.
2. `PayloadPreparer` strips identity fields (`uuid`, `icv`, `pih`, hashes, QR), forces the seller block
   from the registration, the workflow and the invoice number.
3. Idempotency lookup by `(company_id, external_invoice_id)`:
   same payload hash → return existing; different → `DuplicateInvoice`.
4. Insert invoice (`RECEIVED`) + version 1 (original JSON) + log, then validate. Errors/warnings are
   stored; the status becomes `VALIDATION_FAILED` or `READY_FOR_PROCESSING`.
5. A unique-key race on insert is caught and resolved as a repeat.

### 4.2 Issuance (T2) — `ChainService::issue()`

Inside one transaction (optionally wrapped by `CacheAdvisoryLock` when `performance.advisory_chain_lock`):

1. `SELECT … FOR UPDATE` the invoice; re-check state (initial: `READY_FOR_PROCESSING` and no
   issuance; reissue: current issuance `rejected`).
2. `SELECT … FOR UPDATE` the device's `invoice_counters` row → `icv = last_icv + 1`,
   `pih = last_invoice_hash ?? genesis`.
3. Prepare the payload from the current version; for reissues, the rejection policy sets `issued_at`.
4. Generate: validate → UBL XML → hash → XAdES sign → QR. Any exception rolls back; **no ICV is used**.
5. `ArtifactStore::putImmutable()` (content-addressed path under `tenant/c{company}/d{device}/Y/m`).
6. Insert issuance, artifact row, chain entry.
7. CAS update of the counter (`WHERE lock_version = v`); 0 rows → `SequenceException::counterConflict`.
8. Create the workflow submission row (B2C: `deadline_at = issued + 24h`).
9. Reissue only: previous issuance → `superseded`, its errors resolved, reporting/clearance status reset.
10. Transition to `QUEUED`; record the outbox `submit` message (idempotency key `submit:{uuid}:1`).
11. Queue events `InvoiceIssued` (+ `InvoiceReissued`, `CustomerCopyReissued`) for after commit.

**Signing credentials** come from `SigningCredentialProvider`: the active production certificate and the
decrypted key, cached in process memory for `signing_key_memory_ttl`. Developer-portal special case: if
the production certificate's public key does not match the device key, the compliance certificate is
used and the warning `SANDBOX_SIGNING_CERTIFICATE` is stored (sandbox only).

### 4.3 Outbox

- `OutboxRecorder::record()` writes a row in the caller's transaction.
- `OutboxDispatcher` pushes it to the target queue **after commit** (fast path). Skipped on the `sync`
  driver unless `outbox.dispatch_on_sync_driver`.
- `OutboxRelay` (`zatca:outbox:relay`, every minute or `--daemon`) claims due rows with a lease
  (`lease_seconds`) and dispatches them, respecting `queues.high_water_mark`.
- `OutboxJob` runs the handler (`OutboxHandlers`); failures back off (`10·n²` s + jitter, ≤ 1 h);
  after `outbox.max_attempts` the row is dead-lettered with a CRITICAL `queue` error.
- Handlers are idempotent: they re-read state and the submission claim decides who acts.

### 4.4 Submission — `SubmissionRunner::run()`

1. Load the submission; return if final. Assert the invoice's workflow matches the pipeline.
2. **Backpressure:** circuit breaker open (`CircuitBreaker`, per environment), device paused, or rate
   limit (`http.max_rps`) → reschedule without consuming an attempt.
3. **T3 claim:** conditional `UPDATE` to `in_flight` with a fresh `claim_token` and `claimed_until`
   (3 × timeout + 30 s); increments attempts; inserts the `submission_attempts` row (Authorization
   header redacted, XML replaced by a placeholder).
4. **HTTP** via `SubmissionGateway` (reporting or clearance endpoint; `Clearance-Status` header; 303
   fallback uses the reporting endpoint with `Clearance-Status: 0`). Every `Throwable` is caught.
5. **T4 outcome** (fenced by `claim_token`; a stale worker's result is discarded):

| Classified outcome | Trigger | Effect |
|---|---|---|
| `Accepted` / `AcceptedWithWarnings` | ZATCA accepted | Issuance `accepted…`; B2B stores `cleared_xml`; `InvoiceAccepted` |
| `Rejected` | HTTP 400 / `NOT_REPORTED` / `NOT_CLEARED` | Issuance `rejected`; errors from ZATCA stored and grouped; `InvoiceRejected` |
| `ClearanceDeactivated` | B2B HTTP 303 | Marker `CLEARANCE_DEACTIVATED`; re-queued via reporting, or `MANUAL_REVIEW` per config |
| `Transient` | Connection failure, timeout, 429, 5xx | `RETRY_PENDING` with `RetryPolicy` delay, or `FAILED` when exhausted |
| `Unknown` | 200/202 without readable body, unexpected exception | `OUTCOME_UNKNOWN`; identical document resent |
| `Auth` | 401/403, device unavailable | `MANUAL_REVIEW`, submission `failed_permanent` |
| `ClientError` | Other 4xx | `MANUAL_REVIEW` |

`RetryPolicy`: per-workflow backoff list with 50–100 % jitter; B2C delays are compressed so the last
automatic attempt is ≥ `b2c_deadline_margin_hours` before `deadline_at`.

### 4.5 B2C response contract

`IssueResult::toArray()`:
`{status, message, qr, counter, uuid, invoice_id, invoice_hash, processing_status, reporting_deadline, errors?}`.
`httpStatus`: 201 new, 200 replay, 422 invalid. In `sync` reporting mode the issuer calls `submitNow()`
in the request and defers the queued safety-net message by 120 s.

---

## 5. Concurrency guarantees

| Layer | Mechanism | Trusted for correctness? |
|---|---|---|
| Advisory lock (optional) | `Cache::lock("zatca:chain:{device}:{env}")` | No |
| Row lock | `FOR UPDATE` on the counter row | Yes (serialises per device + environment) |
| CAS | `lock_version` compare-and-set | Yes (detects lost updates) |
| Ledger | Unique keys on `chain_entries` | Yes (last line of defence) |
| Verification | `ChainVerifier` (`zatca:chain:verify`, `--deep` re-hashes artifacts) | Detection |

Evidence: `ConcurrentIssuanceTest` runs 6 OS processes × 15 invoices against one device on MariaDB and
asserts contiguous ICVs 1…90, a valid PIH chain and no duplicates. Before InnoDB was forced, the same
test caught MyISAM silently ignoring row locks (the ledger UK blocked the duplicate).

---

## 6. State machines

**Processing status** (`ProcessingStatus`): `RECEIVED → VALIDATION_FAILED | READY_FOR_PROCESSING →
(SIGNED, logged) → QUEUED → SUBMITTING → REPORTED | CLEARED | REJECTED | RETRY_PENDING | OUTCOME_UNKNOWN
| FAILED | MANUAL_REVIEW`. Every transition goes through `ProcessingLogger::transition()`, which writes
`processing_logs` with actor and reason.

**Issuance status** (`IssuanceStatus`): `issued → accepted | accepted_with_warnings | rejected`;
`rejected → superseded` when reissued. Accepted issuances are terminal.

**Submission row status** (`b2c_reporting_submissions` / `b2b_clearance_submissions`): `pending →
in_flight → reported | reported_with_warnings | not_reported` (B2C) or `cleared | cleared_with_warnings |
not_cleared | clearance_deactivated` (B2B), back to `pending` / `unknown` for retries, or `failed`.
Claimable: `pending`, `unknown`, and `in_flight` with an expired `claimed_until`. Final rows are never
claimed again.

**Device lifecycle** (`DeviceLifecycleStatus`): `REGISTERED → KEY_GENERATED → COMPLIANCE_CSID_ISSUED →
COMPLIANCE_CHECKING → COMPLIANCE_PASSED → ACTIVE → RENEWAL_DUE → RENEWING → ACTIVE | RENEWAL_FAILED →
EXPIRED`; `ONBOARDING_FAILED`, `SUSPENDED`, `DECOMMISSIONED`. `canIssue()`: ACTIVE, RENEWAL_DUE,
RENEWING, RENEWAL_FAILED.

---

## 7. Operations (M6) — `InvoiceOperations`

| Operation | Preconditions | Mechanism |
|---|---|---|
| `retry` | Issuance `issued`; status in QUEUED, RETRY_PENDING, FAILED, OUTCOME_UNKNOWN, MANUAL_REVIEW; no live claim | Reset submission to `pending` (keeps `unknown`), record outbox `manual:{uuid}:{n}` or run now |
| `correct` | No issuance (VALIDATION_FAILED/FAILED/READY/MANUAL_REVIEW) or issuance `rejected`; same document type; policy allows | Validate (throw, save nothing) → new version with `changed_fields` (JSON pointers) → `READY_FOR_PROCESSING` (or `MANUAL_REVIEW` with four-eyes) → `ChainService::issue(Correction)` |
| `reissueUnchanged` | Issuance `rejected`; policy allows | `ChainService::issue(ReissueUnchanged)` |
| `approve` | `requires_approval`, `MANUAL_REVIEW`; approver ≠ corrector with four-eyes | Then reprocess |
| `creditNote` / `debitNote` | Original is type 388 and its issuance is accepted | Build payload (type, billing reference, reason, inherited buyer/supply date/subtype) → workflow issuer with `billing_reference_invoice_id` |

**Rejection policies** (`RejectedInvoicePolicy`, built by `RejectionPolicies` from `zatca.correction.*`):
`keepInvoiceNumber()`, `issueDate(rejectedAt, now)`, `requiresCreditNote()`. ⚠ Q-Z2/Q-Z3/Q-Z4.

---

## 8. Monitors

| Class | Command | Logic |
|---|---|---|
| `DeadlineMonitor` | `zatca:deadlines:check` | For unreported B2C issuances: per threshold in `deadline_alert_hours`, once (idempotency via `processing_logs` event `deadline_at_risk_{h}h`) fire `InvoiceReportingDeadlineAtRisk`; past `deadline_at` set the breach flag, CRITICAL error, `InvoiceReportingDeadlineBreached` |
| `Reconciler` | `zatca:reconcile` | Submissions idle ≥ `--older-than` with no pending outbox row, and B2B invoices stuck in READY without an issuance → record a new outbox message |
| `ChainVerifier` | `zatca:chain:verify` | ICV contiguity, PIH linkage, counter head = last entry; `--deep` re-hashes stored XML |
| `CertificateExpiryMonitor` | `zatca:devices:check-expiry` | Status moves + `CertificateExpiring` once per threshold |

---

## 9. Devices and cryptography

- **Keys:** secp256k1 (`csr.curve`), generated with phpseclib, stored as envelopes
  (AES-256-GCM data key wrapped by the master key or KMS) in `device_keys`.
- **CSR:** ZATCA template extension, SAN with the EGS serial (`surname` attribute by default), invoice
  type flags, location and industry. Verified against ZATCA's Security Features standard (Table 1).
- **Onboarding** (`DeviceOnboardingService`): key → CSR → compliance CSID (OTP) → compliance samples on a
  separate sample chain → production CSID. Resumable after compliance failures.
- **Renewal** (`CertificateRenewalService`): new key + CSR, `PATCH` production CSID authenticated with
  the current one; the device keeps signing with the old certificate until the new one is stored.
- **Signing:** `XadesSigner` builds the XAdES-BES signature per ZATCA's profile; `SignatureVerifier`
  checks it. Hash, signature, digests and QR reproduce ZATCA SDK samples byte-for-byte
  (`tests/Unit/Invoice/SdkGoldenTest.php`).
- **XML parsing:** `SecureXml` (`LIBXML_NONET`, no entity substitution).

---

## 10. Security implementation

| Requirement | Implementation |
|---|---|
| No plaintext credentials | Envelope encryption; `previous_encryption_keys` for rotation; optional KMS |
| No stored OTPs | OTP passed through to the client call only; hidden console input |
| No secrets in logs/events | Events carry IDs only; `ErrorRecorder::sanitize()` on stored responses; Authorization header stored as `[redacted]` |
| No public exposure | Private artifact disk; no route serves artifacts or keys |
| TLS | Guzzle `verify` on; boot fails if disabled in production; `ca_bundle` support |
| Tamper evidence | Hash-chained `audit_logs` (`zatca:audit:verify`), SHA-256 check on artifact reads, append-only models |
| Integrity of history | Versions/issuances/attempts never updated in place (except status fields) or deleted |

---

## 11. Testing

| Suite | Content |
|---|---|
| Unit | Validation rules, calculations, UBL output, hashing, signing, QR, CSR, SDK golden files |
| Integration | Schema, onboarding/renewal against `FakeZatca`, issuance, outbox, retries, circuit breaker, operations (17 tests), concurrency (multi-process) |
| Live (manual) | Developer-portal scripts under `scratchpad`/`tools`; results recorded in ARCHITECTURE §2.3 |

`FakeZatca` signs submitted CSRs with a test CA (`csidForSubmittedCsr()`) and scripts responses
(`reported()`, `cleared()`, `notReported()`, `notCleared()`, transport failures).

Running: `vendor/bin/phpunit` (SQLite). MariaDB/MySQL: `ZATCA_TEST_DB=mysql DB_PORT=… vendor/bin/phpunit
--testsuite Integration`. CI (`.github/workflows/ci.yml`): core-boundary check, Laravel 9–13 × PHP
matrix × lowest/stable, database jobs.

Current status: 135 tests on Laravel 9/10/11/12/13; MariaDB integration suite (64 tests) on L9 and L13.
PostgreSQL, MySQL 8 and SQL Server not yet run locally (they are in the CI matrix design).

---

## 12. Design vs. as-built

| Designed (ARCHITECTURE) | Status |
|---|---|
| Batch endpoint `POST /b2c/invoices/batch` | Not built (config key `api.batch_max` exists, no route) |
| `/health` readiness endpoint | Not built |
| Device-pool routing (least-busy device) | Not built: the caller chooses the device |
| Signed XML staged in a DB column, offloaded to S3 later | Not built: XML is written to the artifact disk inside T2 |
| `zatca:documents:gc` for orphaned artifacts after rollback | Not built (orphans are content-addressed and harmless) |
| `zatca:bench` and published throughput numbers | M8 |
| Dashboard, permissions matrix, bulk operations | M7 (tables exist) |
| `database` tenancy mode | Config accepted, no connection switching |
| Status lookup / reconciliation against ZATCA (Q-Z6/Q-Z7) | Not available from ZATCA; `Unknown` outcomes resend identical bytes |

## 13. Open ZATCA questions (⚠)

See ARCHITECTURE §22.1. Configurable defaults currently cover Q-Z2 (B2B IRN), Q-Z3 (B2C IRN/date),
Q-Z4 (credit note for rejected), Q-Z8 (303 handling), Q-Z15 (retention). Q-Z11 (rate limits) is a
config value (`http.max_rps`) to be tuned with ZATCA.

## 14. Extension points

| Contract | Config | Purpose |
|---|---|---|
| `Core\Contracts\Tenancy\TenantResolver` | `tenancy.resolver` | Current tenant |
| `Core\Contracts\Security\KeyManagementService` | `security.kms` | External key management |
| `ArtifactStore` (container binding) | `storage.*` | Alternative artifact storage |
| `PermissionResolver` | `authorization.resolver` | Dashboard permissions (M7) |
| Events | — | All integrations with the host app |
