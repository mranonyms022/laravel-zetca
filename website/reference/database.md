# Database tables

All tables use the prefix `zatca_` (configurable). On MySQL/MariaDB they are InnoDB.

::: danger Read, don't write
Reading these tables for reports is fine. Never insert, update or delete counters, issuances, chain
entries or submissions yourself: the invoice chain's guarantees depend on the package doing it inside
locked transactions.
:::

## Organisation and devices

| Table | Content |
|---|---|
| `companies` | Your companies (tenants of the package); `external_ref` = your own ID |
| `vat_registrations` | VAT number, legal name, seller IDs, address |
| `branches` | Stores / branches of a company; `external_ref` = your own ID |
| `devices` | Devices (EGS units), environment, lifecycle status |
| `device_keys` | Encrypted private keys (history kept) |
| `certificates` | Compliance and production certificates, validity |
| `device_onboardings` | Onboarding attempts with step results |
| `device_renewals` | Renewal attempts |

## Invoices

| Table | Content |
|---|---|
| `invoices` | Invoice records and current statuses; `source_type`/`source_id` link to your record; `credited_amount`, `debited_amount`, `cancellation_invoice_id`, `closed_at` |
| `invoice_lines` | Calculated lines per signed document (append-only) |
| `invoice_vat_breakdown` | VAT per category/rate per signed document (append-only) |
| `invoice_versions` | Original JSON and corrections (append-only) |
| `invoice_issuances` | Legal documents: UUID, ICV, PIH, hash, QR, status |
| `invoice_counters` | Per device: last ICV and last hash (the chain head) |
| `chain_entries` | Ledger of every ICV per device (unique keys prevent duplicates and forks) |
| `invoice_artifacts` | Pointers to stored XML (signed, cleared) with SHA-256 |
| `invoice_drafts` | Drafts (reserved for the dashboard) |

## Submission

| Table | Content |
|---|---|
| `b2c_reporting_submissions` | One per B2C issuance: status, attempts, lease, next retry |
| `b2b_clearance_submissions` | One per B2B issuance |
| `submission_attempts` | Every HTTP call to ZATCA: status, duration, sanitised response |
| `outbox` | Transactional outbox for queue dispatch |

## Errors, history, audit

| Table | Content |
|---|---|
| `errors` | Errors with category, severity, code, resolution |
| `error_groups` | Grouped similar errors with counts |
| `warnings` | Local and ZATCA warnings |
| `processing_logs` | Every status transition (time, actor, reason) |
| `audit_logs` | Hash-chained audit trail |

## Other

| Table | Content |
|---|---|
| `settings` | Runtime settings |
| `stats_daily` | Daily counters (dashboard) |
| `bulk_operations`, `bulk_operation_items` | Bulk actions (dashboard) |
