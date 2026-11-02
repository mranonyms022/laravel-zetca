# Configuration

All options live in `config/zatca.php`. Values marked **(future)** are read by components that ship in
later milestones; setting them now is harmless.

## Environment and endpoints

| Key | Env | Default | Notes |
|---|---|---|---|
| `environment` | `ZATCA_ENVIRONMENT` | `sandbox` | `sandbox` (developer portal), `simulation`, `production`. Validated at boot |
| `endpoints.*` | `ZATCA_*_URL` | ZATCA gateway URLs | Override only for a proxy/gateway |
| `timezone` | `ZATCA_TIMEZONE` | `Asia/Riyadh` | Used to render IssueDate/IssueTime; stored timestamps are UTC |

Each device stores its own environment. Counters, certificates and hash chains are never shared between
environments.

## Database

| Key | Env | Default | Notes |
|---|---|---|---|
| `database.connection` | `ZATCA_DB_CONNECTION` | default connection | Put package tables on a dedicated connection |
| `database.table_prefix` | `ZATCA_TABLE_PREFIX` | `zatca_` | Lowercase letters, digits, `_`; max 16 chars. Set **before** the first migration |
| `database.foreign_keys` | `ZATCA_DB_FOREIGN_KEYS` | `true` | Disable only for partitioned MySQL tables |
| `database.max_payload_bytes` | – | 1 MB | Maximum invoice JSON size **(future)** |

## Multi-tenancy

| Key | Env | Default | Notes |
|---|---|---|---|
| `tenancy.mode` | `ZATCA_TENANCY_MODE` | `none` | `none`, `column` (shared DB, `tenant_id` column), `database` (DB per tenant) |
| `tenancy.resolver` | – | `null` | Class implementing `CodingTriplets\Zatca\Core\Contracts\Tenancy\TenantResolver` |

Example resolver:

```php
use CodingTriplets\Zatca\Core\Contracts\Tenancy\TenantResolver;

final class CurrentTeamTenantResolver implements TenantResolver
{
    public function current(): ?string
    {
        return auth()->user()?->current_team_id ? (string) auth()->user()->current_team_id : null;
    }
}
```

```php
// config/zatca.php
'tenancy' => ['mode' => 'column', 'resolver' => App\Zatca\CurrentTeamTenantResolver::class],
```

In `column` mode, package models are filtered by the current tenant and new rows are stamped with it.
When the resolver returns `null` (e.g. system jobs), no filter is applied.

## Security

| Key | Env | Default | Notes |
|---|---|---|---|
| `security.encryption_key` | `ZATCA_ENCRYPTION_KEY` | – | Dedicated master key. Falls back to `APP_KEY` with a warning in production |
| `security.previous_encryption_keys` | `ZATCA_PREVIOUS_ENCRYPTION_KEYS` | – | Comma-separated old master keys, still used to decrypt after a rotation |
| `security.kms` | – | `null` | Class implementing `Core\Contracts\Security\KeyManagementService` (AWS KMS, Vault…) |
| `security.verify_tls` | `ZATCA_VERIFY_TLS` | `true` | **Cannot be disabled in production** (boot fails) |
| `security.ca_bundle` | `ZATCA_CA_BUNDLE` | – | Custom CA bundle path |

## CSR

| Key | Env | Default |
|---|---|---|
| `csr.curve` | – | `secp256k1` |
| `csr.solution_provider` | `ZATCA_SOLUTION_PROVIDER` | `CodingTriplets` |
| `csr.solution_model` | `ZATCA_SOLUTION_MODEL` | `laravel-zatca` |
| `csr.serial_attribute` | – | `surname` | Attribute carrying the EGS serial: `surname` (2.5.4.4, what OpenSSL-based integrations send and ZATCA accepts) or `serialNumber` (2.5.4.5) ⚠ |

The provider and model are used to build the EGS serial number `1-<provider>|2-<model>|3-<serial>`.

## Storage

| Key | Env | Default | Notes |
|---|---|---|---|
| `storage.disk` | `ZATCA_ARTIFACT_DISK` | `local` | Must be **private**. Use a shared disk on multi-server setups |
| `storage.root` | – | `zatca` | Folder inside the disk |
| `storage.encrypt_local` | `ZATCA_ENCRYPT_ARTIFACTS` | `false` | App-level encryption for local disks (S3: use SSE-KMS) |

## HTTP, queues and retries

| Key | Default | Notes |
|---|---|---|
| `http.timeout` / `connect_timeout` | 30 / 10 s | |
| `http.max_rps` | 10 | Global outbound rate (ZATCA does not publish limits) |
| `http.max_in_flight_per_device` | 2 | |
| `queues.connection` | default | |
| `queues.names.*` | `zatca-b2b-generation`, `zatca-b2b-clearance`, `zatca-b2b-retry`, `zatca-b2c-generation`, `zatca-b2c-reporting`, `zatca-b2c-retry`, `zatca-device-onboarding`, `zatca-device-renewal`, `zatca-outbox`, `zatca-maintenance` | |
| `queues.high_water_mark` | 5000 | Outbox stops feeding a queue above this backlog |
| `retry.b2b` / `retry.b2c` | 10 attempts, exponential | |
| `retry.b2c_deadline_margin_hours` | 4 | Last automatic B2C attempt this long before the deadline |
| `b2c.reporting_deadline_hours` | 24 | |
| `b2c.reporting_mode` | `queue` | `queue`: `issue()` answers in milliseconds and reporting runs on the queue. `sync`: reporting happens inside the request, which adds ZATCA's response time |
| `http.circuit.failures` / `open_seconds` | 5 / 60 | Per-environment circuit breaker on transport failures |
| `outbox.schedule` | `true` | Schedules `zatca:outbox:relay` (every minute), plus `zatca:outbox:prune` and `zatca:chain:verify` (daily) |
| `outbox.max_attempts` | 10 | Handler failures allowed before a message is dead-lettered (CRITICAL `queue` error) |
| `outbox.lease_seconds` | 600 | A dispatched message whose job has not finished within this time is dispatched again |
| `outbox.dispatch_on_sync_driver` | `false` | With the `sync` queue driver, messages are left to the relay instead of running inside the request |
| `performance.advisory_chain_lock` | `false` | Extra cache lock around issuance. Correctness never depends on it |
| `api.enabled` / `prefix` / `middleware` | `false` / `api/zatca` / `['api']` | Optional HTTP endpoints. Add your own auth middleware |
| `b2b.require_buyer_identification` | `true` | Reject B2B invoices without buyer VAT or other buyer ID (BR-KSA-81) before using an ICV |

## Corrections **(future)**

Defaults for rejected-invoice handling. Some are pending official ZATCA confirmation
(see ARCHITECTURE §3 and §22).

| Key | Default |
|---|---|
| `correction.b2b_keep_invoice_number` | `true` |
| `correction.b2b_update_issue_date` | `true` |
| `correction.b2c_keep_invoice_number` | `true` |
| `correction.b2c_keep_issue_date` | `true` |
| `correction.credit_note_for_rejected` | `false` |
| `correction.four_eyes` | `false` |

## Devices

| Key | Env | Default | Notes |
|---|---|---|---|
| `devices.renewal_warning_days` | – | `[30, 14, 7, 1]` | `CertificateExpiring` fires once per threshold; the largest one starts `RENEWAL_DUE` |
| `devices.schedule_expiry_check` | – | `true` | Registers `zatca:devices:check-expiry` daily at 03:15 (needs `schedule:run` in cron) |
| `devices.sandbox_skip_compliance_checks` | `ZATCA_SANDBOX_SKIP_COMPLIANCE_CHECKS` | `false` | Developer-portal sandbox only: finish onboarding without compliance checks |

## Retention, UI, authorization **(future)**

| Key | Default |
|---|---|
| `retention.years` | 6 (confirm with your tax advisor) |
| `retention.outbox_processed_days` | 14 |
| `ui.enabled` / `ui.prefix` / `ui.domain` / `ui.middleware` / `ui.guard` | `true` / `admin/zatca` / – / `['web','auth']` / – |
| `authorization.resolver` | `null` (uses Laravel Gate / `$user->can()`) |
