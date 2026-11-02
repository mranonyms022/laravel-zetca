# Artisan commands

## Setup

| Command | Description |
|---|---|
| `zatca:taxpayer:register` | Register a company / VAT registration (and optionally its first branch). Options: `--name`, `--name-ar`, `--legal-name`, `--vat`, `--group-tin`, `--crn`, `--street`, `--building`, `--district`, `--city`, `--postal-code`, `--branch-code`, `--branch-name` |
| `zatca:device:register` | Register a device. Options: `--vat-registration`, `--branch`, `--name`, `--types` (1000/0100/1100), `--environment`, `--industry`, `--location`, `--serial` |
| `zatca:device:onboard {device} [--resume]` | Onboard with an OTP (asked as hidden input). `--resume` continues after failed compliance checks |
| `zatca:device:renew {device}` | Renew the production certificate (new OTP) |
| `zatca:devices [--status=]` | List devices with status and certificate expiry |

## Invoices

| Command | Description |
|---|---|
| `zatca:invoice show {invoice}` | Summary: number, workflow, type, statuses, issuance and attempt counts, unresolved errors, last error code |
| `zatca:invoice retry {invoice} [--now] [--actor=]` | Resend the identical document |
| `zatca:invoice correct {invoice} --file=corrected.json --reason= [--actor=]` | Save a corrected version and reissue |
| `zatca:invoice reissue {invoice} --reason= [--actor=]` | New issuance from the unchanged JSON |
| `zatca:invoice approve {invoice} --actor=` | Approve a correction (four-eyes) |

`{invoice}` is the `invoice_id` (ULID) returned when issuing.

## Background and maintenance

| Command | Schedule | Description |
|---|---|---|
| `zatca:outbox:relay [--daemon] [--sleep=1] [--limit=500] [--max-time=0]` | every minute | Push due outbox messages to the queues |
| `zatca:outbox:prune [--days=]` | daily 03:30 | Delete processed outbox messages (dead letters are kept) |
| `zatca:deadlines:check` | every 10 min | B2C deadline alerts and breaches |
| `zatca:reconcile [--older-than=300]` | every 15 min | Re-queue submissions and B2B generations without a pending message |
| `zatca:chain:verify [--device=] [--deep]` | daily 02:45 | Verify ICV/PIH chains |
| `zatca:devices:check-expiry` | daily 03:15 | Certificate expiry status and warnings |
| `zatca:audit:verify` | — | Verify the audit log hash chain |
