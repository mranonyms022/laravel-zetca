# Configuration

All options live in `config/zatca.php` (publish with `php artisan vendor:publish --tag=zatca-config`).
Options marked ⚠ follow the package's reading of ZATCA's documents where ZATCA has not stated the rule
explicitly; confirm them with ZATCA or your tax advisor.

## Environment and endpoints

| Key | Env | Default | Description |
|---|---|---|---|
| `environment` | `ZATCA_ENVIRONMENT` | `sandbox` | `sandbox`, `simulation` or `production`. Default for new devices |
| `endpoints.sandbox` | `ZATCA_SANDBOX_URL` | `…/e-invoicing/developer-portal` | |
| `endpoints.simulation` | `ZATCA_SIMULATION_URL` | `…/e-invoicing/simulation` | |
| `endpoints.production` | `ZATCA_PRODUCTION_URL` | `…/e-invoicing/core` | |
| `timezone` | `ZATCA_TIMEZONE` | `Asia/Riyadh` | Used to render IssueDate/IssueTime; stored times are UTC |

## Database

| Key | Env | Default | Description |
|---|---|---|---|
| `database.connection` | `ZATCA_DB_CONNECTION` | `null` | Connection for package tables (`null` = default) |
| `database.table_prefix` | `ZATCA_TABLE_PREFIX` | `zatca_` | Lowercase, max 16 characters |
| `database.foreign_keys` | `ZATCA_DB_FOREIGN_KEYS` | `true` | Set `false` only for partitioned MySQL tables |
| `database.max_payload_bytes` | | 1 MiB | Maximum invoice JSON size |
| `database.store_lines` | `ZATCA_STORE_LINES` | `true` | Store calculated lines and VAT breakdown per document |

## Tenancy

| Key | Env | Default | Description |
|---|---|---|---|
| `tenancy.mode` | `ZATCA_TENANCY_MODE` | `none` | `none`, `column`, `database` (see [tenancy](/guide/tenancy)) |
| `tenancy.resolver` | | `null` | Class implementing `TenantResolver` |

## Security

| Key | Env | Default | Description |
|---|---|---|---|
| `security.encryption_key` | `ZATCA_ENCRYPTION_KEY` | `null` | Master key for private keys and secrets. Falls back to `APP_KEY` with a warning |
| `security.previous_encryption_keys` | `ZATCA_PREVIOUS_ENCRYPTION_KEYS` | `[]` | Old keys, comma-separated, after a rotation |
| `security.kms` | | `null` | Class implementing `KeyManagementService` |
| `security.verify_tls` | `ZATCA_VERIFY_TLS` | `true` | Cannot be `false` in production |
| `security.ca_bundle` | `ZATCA_CA_BUNDLE` | `null` | Path to a CA bundle |

## Certificate requests (CSR)

| Key | Env | Default | Description |
|---|---|---|---|
| `csr.curve` | | `secp256k1` | Key curve (as in ZATCA's SDK) |
| `csr.solution_provider` | `ZATCA_SOLUTION_PROVIDER` | `CodingTriplets` | Part 1 of the EGS serial |
| `csr.solution_model` | `ZATCA_SOLUTION_MODEL` | `laravel-zatca` | Part 2 of the EGS serial |
| `csr.serial_attribute` | | `surname` | `surname` (OID 2.5.4.4) or `serialNumber` (2.5.4.5) for the EGS serial |

## Storage

| Key | Env | Default | Description |
|---|---|---|---|
| `storage.disk` | `ZATCA_ARTIFACT_DISK` | `local` | **Private** disk for signed/cleared XML |
| `storage.root` | | `zatca` | Folder on the disk |
| `storage.encrypt_local` | `ZATCA_ENCRYPT_ARTIFACTS` | `false` | Encrypt stored XML |

## HTTP (calls to ZATCA)

| Key | Env | Default | Description |
|---|---|---|---|
| `http.timeout` | | 30 | Seconds |
| `http.connect_timeout` | | 10 | Seconds |
| `http.max_rps` | `ZATCA_MAX_RPS` | 10 | Requests per second to ZATCA |
| `http.max_in_flight_per_device` | `ZATCA_MAX_IN_FLIGHT_PER_DEVICE` | 2 | Parallel calls per device |
| `http.circuit.failures` | | 5 | Consecutive transport failures before the circuit opens |
| `http.circuit.open_seconds` | | 60 | How long submissions pause |

## Outbox

| Key | Default | Description |
|---|---|---|
| `outbox.schedule` | `true` | Register relay, prune, chain verify, deadline check and reconcile with the scheduler |
| `outbox.max_attempts` | 10 | Handler failures before a message is dead-lettered |
| `outbox.lease_seconds` | 600 | A dispatched message is dispatched again if its job has not finished by then |
| `outbox.dispatch_on_sync_driver` | `false` | With the `sync` queue driver, leave jobs to the relay instead of running them in the request |

## Queues

| Key | Env | Default | Description |
|---|---|---|---|
| `queues.connection` | `ZATCA_QUEUE_CONNECTION` | `null` | Queue connection (`null` = default) |
| `queues.names.b2c_reporting` | | `zatca-b2c-reporting` | B2C reporting |
| `queues.names.b2c_retry` | | `zatca-b2c-retry` | B2C retries |
| `queues.names.b2b_generation` | | `zatca-b2b-generation` | Signing of `b2b()->submit()` invoices |
| `queues.names.b2b_clearance` | | `zatca-b2b-clearance` | B2B clearance |
| `queues.names.b2b_retry` | | `zatca-b2b-retry` | B2B retries |
| `queues.names.b2c_generation` | | `zatca-b2c-generation` | Reserved (B2C is signed in the request) |
| `queues.names.device_onboarding` | | `zatca-device-onboarding` | Reserved (onboarding runs in the calling process: OTPs are never queued) |
| `queues.names.device_renewal` | | `zatca-device-renewal` | Reserved |
| `queues.names.outbox` | | `zatca-outbox` | Reserved |
| `queues.names.maintenance` | | `zatca-maintenance` | Reserved |
| `queues.high_water_mark` | | 5000 | Pending submissions before backpressure (`503`) |

## Retries

| Key | Default | Description |
|---|---|---|
| `retry.b2b.max_attempts` | 10 | |
| `retry.b2b.backoff_seconds` | 60 … 43200 | Delay before each retry (randomised 50–100 %) |
| `retry.b2c.max_attempts` | 10 | |
| `retry.b2c.backoff_seconds` | 30 … 10800 | |
| `retry.b2c_deadline_margin_hours` | 4 | Last automatic B2C attempt at least this long before the deadline |

## B2C

| Key | Env | Default | Description |
|---|---|---|---|
| `b2c.reporting_deadline_hours` | | 24 | Reporting window after issuance |
| `b2c.deadline_alert_hours` | | `[4, 1]` | Hours before the deadline at which `InvoiceReportingDeadlineAtRisk` fires |
| `b2c.reporting_mode` | `ZATCA_B2C_REPORTING_MODE` | `queue` | `queue` or `sync` |
| `b2c.infer_standard_vat_category` | | `true` | Missing category + 15 % → `S` with a warning |

## B2B

| Key | Default | Description |
|---|---|---|
| `b2b.require_buyer_identification` | `true` | Reject buyers without VAT number or other ID before signing (BR-KSA-81) |
| `b2b.on_clearance_deactivated` ⚠ | `report` | On HTTP 303: `report` the same document, or `manual_review` |

## Corrections ⚠

| Key | Default | Description |
|---|---|---|
| `correction.b2b_keep_invoice_number` | `true` | Corrected B2B document keeps the invoice number |
| `correction.b2b_update_issue_date` | `true` | Corrected B2B document gets the reissue date |
| `correction.b2c_keep_invoice_number` | `true` | Corrected B2C document keeps the invoice number |
| `correction.b2c_keep_issue_date` | `true` | Corrected B2C document keeps the original issue date |
| `correction.credit_note_for_rejected` | `false` | Refuse corrections of rejected invoices (credit note required instead) |
| `correction.four_eyes` | `false` | Corrections need approval by a different user |

## API

| Key | Env | Default | Description |
|---|---|---|---|
| `api.enabled` | `ZATCA_API_ENABLED` | `false` | Register the [HTTP API](/guide/http-api) |
| `api.prefix` | `ZATCA_API_PREFIX` | `api/zatca` | URL prefix |
| `api.middleware` | | `['api']` | Add your authentication here |
| `api.batch_max` | | 500 | Reserved: batch endpoint not available yet |

## Performance

| Key | Env | Default | Description |
|---|---|---|---|
| `performance.signing_key_memory_ttl` | `ZATCA_SIGNING_KEY_TTL` | 300 | Seconds a decrypted signing key stays in worker memory (0 = off) |
| `performance.max_in_flight_issuance` | `ZATCA_MAX_IN_FLIGHT_ISSUANCE` | 200 | API: concurrent issuance requests before `429` |
| `performance.counter_lock_wait_ms` | | 2000 | Wait for a busy device |
| `performance.max_in_flight_per_device` | | 8 | Reserved: not enforced yet |
| `performance.shed_when_outbox_lag_seconds` | | 300 | Reserved: not enforced yet |
| `performance.advisory_chain_lock` | | `false` | Extra cache lock around issuance |

## Devices

| Key | Env | Default | Description |
|---|---|---|---|
| `devices.renewal_warning_days` | | `[30, 14, 7, 1]` | `CertificateExpiring` thresholds |
| `devices.schedule_expiry_check` | | `true` | Register the daily expiry check |
| `devices.sandbox_skip_compliance_checks` | `ZATCA_SANDBOX_SKIP_COMPLIANCE_CHECKS` | `false` | Developer portal only |

## Retention

| Key | Default | Description |
|---|---|---|
| `retention.years` ⚠ | 6 | Retention period; confirm with your tax advisor |
| `retention.outbox_processed_days` | 14 | Processed outbox rows are deleted after this |

## Dashboard

| Key | Env | Default | Description |
|---|---|---|---|
| `ui.enabled` | `ZATCA_UI_ENABLED` | `true` | Register the [dashboard](/guide/dashboard) routes |
| `ui.prefix` | `ZATCA_UI_PREFIX` | `admin/zatca` | URL prefix |
| `ui.domain` | `ZATCA_UI_DOMAIN` | `null` | Serve only on this domain |
| `ui.middleware` | | `['web', 'auth']` | Middleware stack (your authentication) |
| `ui.guard` | `ZATCA_UI_GUARD` | `null` | Auth guard for the current user (`null` = default) |
| `ui.route_name_prefix` | | `zatca.` | Route name prefix |
| `ui.theme` | | `light` | `light`, `dark` or `auto` |
| `authorization.resolver` | | `null` | Class implementing `PermissionResolver` (`null` = `Zatca::auth()` callback, then Gates/Spatie) |
