# Monitoring and daily checks

## What to watch

| Signal | Where | Healthy |
|---|---|---|
| Invoices in `REJECTED`, `FAILED`, `MANUAL_REVIEW` | `zatca_invoices.processing_status` | 0, or handled within a day |
| B2C invoices close to the deadline | `InvoiceReportingDeadlineAtRisk` event | Never fires |
| Unresolved CRITICAL errors | `zatca_errors` (`severity = critical`, `resolved_at` null) | 0 |
| Dead-lettered outbox messages | `zatca_outbox.dead_lettered_at` not null | 0 |
| Outbox lag | Oldest unprocessed `zatca_outbox.available_at` | Seconds |
| Device certificates | `php artisan zatca:devices` | No `EXPIRED`, renewals planned |
| Hash chains | `zatca:chain:verify` (daily) | Exit code 0 |
| Audit log | `zatca:audit:verify` | Exit code 0 |

Example queries:

```sql
-- invoices that need a human
SELECT public_id, workflow, invoice_number, processing_status, updated_at
FROM zatca_invoices
WHERE processing_status IN ('REJECTED', 'FAILED', 'MANUAL_REVIEW')
ORDER BY updated_at;

-- most frequent errors this week
SELECT code, COUNT(*) FROM zatca_errors
WHERE created_at > NOW() - INTERVAL 7 DAY GROUP BY code ORDER BY 2 DESC;
```

## Built-in checks

| Command | Purpose |
|---|---|
| `zatca:chain:verify [--device=] [--deep]` | Counters contiguous, each PIH equals the previous hash, counter head matches. `--deep` also re-hashes every stored XML |
| `zatca:deadlines:check` | Fire deadline alerts, mark breaches |
| `zatca:reconcile` | Re-queue work whose queue message was lost |
| `zatca:audit:verify` | Detect modified or deleted audit entries |
| `zatca:devices` | Device status and certificate expiry |

All run on the scheduler; you can also run them by hand. A failing `zatca:chain:verify` is a serious
incident: stop issuing on that device and investigate before continuing.

## Errors

Every error is stored with a category (`json_validation`, `business_rule`, `xml_generation`, `certificate`,
`signing`, `authentication`, `api_connection`, `zatca_rejection`, `queue`, `database`, `sequence`, …), a severity, the ZATCA code and message, and whether it was resolved. Similar errors are
grouped in `zatca_error_groups` with a count, so one bad template that rejects 500 invoices shows up as
one group.

## Logs

The package logs to your default log channel. Logs never contain private keys, CSID secrets, OTPs or
full invoice XML. `zatca_submission_attempts` stores the hash and UUID instead of the signed XML, and
ZATCA's response with sensitive values removed.

## Dashboard

A web dashboard (invoice lists, filters, error groups, devices, actions) is planned for milestone M7.
Until then use the commands above, the tables and the events.
