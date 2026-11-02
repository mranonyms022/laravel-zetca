# Statuses and lifecycle

Each invoice has five independent status fields, so "what went wrong where" is always clear.

| Field | Values |
|---|---|
| `processing_status` | Where the invoice is in the pipeline (main status) |
| `validation_status` | `PENDING`, `VALID`, `VALID_WITH_WARNINGS`, `INVALID` |
| `submission_status` | `NOT_STARTED`, `PENDING`, `IN_FLIGHT`, `DELIVERED`, `UNKNOWN`, `FAILED_TRANSIENT`, `FAILED_PERMANENT` |
| `reporting_status` (B2C) | `NOT_APPLICABLE`, `PENDING`, `REPORTED`, `REPORTED_WITH_WARNINGS`, `NOT_REPORTED` |
| `clearance_status` (B2B) | `NOT_APPLICABLE`, `PENDING`, `CLEARED`, `CLEARED_WITH_WARNINGS`, `NOT_CLEARED`, `CLEARANCE_DEACTIVATED` |

## Processing status

| Status | Meaning | What to do |
|---|---|---|
| `RECEIVED` | Stored, about to be validated | Nothing |
| `VALIDATION_FAILED` | The payload is invalid. No counter was used | [Correct](./corrections#b-correct-and-reprocess) the JSON |
| `READY_FOR_PROCESSING` | Valid, waiting to be signed | Nothing (B2B `submit()`: a worker signs it) |
| `QUEUED` | Signed, waiting to be sent to ZATCA | Nothing |
| `SUBMITTING` | A worker is talking to ZATCA | Nothing |
| `REPORTED` | ZATCA accepted the report | Done |
| `CLEARED` | ZATCA cleared the invoice | Send the cleared XML to the buyer |
| `REJECTED` | ZATCA refused the document | Read the errors, then correct, reissue or cancel |
| `RETRY_PENDING` | Temporary failure, retry scheduled (`next_retry_at`) | Nothing |
| `OUTCOME_UNKNOWN` | No readable answer (e.g. timeout after sending). The same document will be resent | Nothing |
| `FAILED` | Retries exhausted, or signing failed (certificate problem) | Fix the cause, then [retry](./corrections#a-retry-the-submission) |
| `MANUAL_REVIEW` | Stopped for a human: authentication refused, request refused, or a correction waiting for approval | Investigate; retry or approve |

## Typical paths

```
B2C   RECEIVED → READY_FOR_PROCESSING → QUEUED → SUBMITTING → REPORTED
B2B   RECEIVED → READY_FOR_PROCESSING → QUEUED → SUBMITTING → CLEARED

temporary failure   … SUBMITTING → RETRY_PENDING → QUEUED → SUBMITTING → REPORTED
rejection           … SUBMITTING → REJECTED → (correct) READY_FOR_PROCESSING → QUEUED → … → REPORTED
invalid payload     RECEIVED → VALIDATION_FAILED → (correct) READY_FOR_PROCESSING → …
```

## Where the details are

| Table | Content |
|---|---|
| `zatca_invoices` | The record and its current statuses |
| `zatca_invoice_versions` | Original JSON and every correction |
| `zatca_invoice_issuances` | Every legal document (UUID, ICV, hash, QR) and its status (`issued`, `accepted`, `accepted_with_warnings`, `rejected`, `superseded`) |
| `zatca_submission_attempts` | Every call to ZATCA with the (redacted) request, the response, HTTP status and duration |
| `zatca_errors` / `zatca_error_groups` | Errors with category, severity, code, resolution |
| `zatca_warnings` | Local and ZATCA warnings |
| `zatca_processing_logs` | Every status change with time, actor and message |

From the command line:

```bash
php artisan zatca:invoice show 01M6E1Y8WXFJ0RK6YXMP4JN9WX
```
