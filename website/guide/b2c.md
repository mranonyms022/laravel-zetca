# B2C: simplified invoices

Simplified tax invoices are given to the customer immediately (with a QR code) and **reported** to ZATCA
within 24 hours.

## Issue an invoice

```php
use CodingTriplets\Zatca\Laravel\Facades\Zatca;

$result = Zatca::b2c()->issue($payload, [
    'device' => $deviceId,          // device ID or device UUID (required)
    'external_id' => 'ORDER-98765', // optional idempotency key (default: invoice_number)
    'actor_id' => auth()->id(),     // optional, recorded in the history
]);

return response()->json($result->toArray(), $result->httpStatus);
```

### What happens during the call

1. The payload is stored unchanged (version 1) and validated.
2. The device's next counter (ICV) and previous hash (PIH) are taken under a database lock.
3. The XML is generated, hashed and signed; the QR code is built; the XML is stored.
4. A reporting job is queued (after the transaction commits).
5. The result is returned. **No call to ZATCA happens in this request** (default `queue` mode).

### Response

```json
{
  "status": true,
  "message": "invoice queued",
  "qr": "AR1NYXhpbXVtIFNwZWVk...",
  "counter": 10452,
  "uuid": "540ce75e-410a-4afa-8630-e603d05a6f13",
  "invoice_id": "01M6E1Y8WXFJ0RK6YXMP4JN9WX",
  "invoice_hash": "I5/YMabWxbVkgy1TisyXDP6qiqDrzTlGwgdQ0+16WdE=",
  "processing_status": "QUEUED",
  "reporting_deadline": "2026-11-03T14:57:29Z"
}
```

| Field | Meaning |
|---|---|
| `status` | `true` when the invoice was issued (or already existed) |
| `message` | `invoice queued`, `invoice reported`, `invoice not reported`, `validation failed`, … |
| `qr` | Base64 QR payload. Render it as a QR code on the receipt |
| `counter` | The invoice counter (ICV) of the document |
| `uuid` | The document UUID |
| `invoice_id` | The package's ID for the invoice record. Use it for lookups and operations |
| `invoice_hash` | The document hash |
| `processing_status` | See [Statuses](./statuses) |
| `reporting_deadline` | UTC time by which ZATCA must have the report |
| `errors` | Present when validation failed or ZATCA rejected the document |

`$result->httpStatus` is 201 for a new invoice, 200 for a repeated request, 422 for invalid data.

## Printing the receipt

- Print the QR code from `qr` (it already contains seller name, VAT number, time, totals, hash and
  signature as required by ZATCA).
- The signed XML is stored by the package. You do not need to give the XML to a consumer.

## Reporting modes

| `ZATCA_B2C_REPORTING_MODE` | Behaviour | When to use |
|---|---|---|
| `queue` (default) | Returns right after signing; a worker reports | POS, web shops, any volume |
| `sync` | Also reports inside the request; the response contains ZATCA's answer | Low volume, when you must show the ZATCA result immediately |

In `sync` mode the response time includes ZATCA's response time (typically several hundred milliseconds
or more). A queued safety-net message is still recorded in case the request dies.

## After reporting

When the worker has the answer, the invoice is updated and an event fires:

| ZATCA result | `processing_status` | `reporting_status` | Event |
|---|---|---|---|
| Reported | `REPORTED` | `REPORTED` | `InvoiceAccepted` |
| Reported with warnings | `REPORTED` | `REPORTED_WITH_WARNINGS` | `InvoiceAccepted` (`withWarnings = true`) |
| Rejected | `REJECTED` | `NOT_REPORTED` | `InvoiceRejected` |
| Temporary failure | `RETRY_PENDING` | `PENDING` | (retried automatically) |
| Attempts exhausted | `FAILED` | `PENDING` | `InvoiceSubmissionFailed` |
| Authentication refused | `MANUAL_REVIEW` | `PENDING` | `InvoiceSubmissionFailed` |

Look it up any time:

```php
$invoice = \CodingTriplets\Zatca\Laravel\Database\Models\Invoice::where('public_id', $invoiceId)->first();
$invoice->processing_status;   // ProcessingStatus enum
$invoice->reporting_status;
```

or `GET /api/zatca/invoices/{invoice_id}` with the [HTTP API](./http-api).

## The 24-hour deadline

ZATCA requires simplified invoices to be reported within 24 hours of issuance. The package:

- retries temporary failures on a schedule compressed to finish before the deadline;
- fires `InvoiceReportingDeadlineAtRisk` when an invoice is still unreported 4 hours and 1 hour before
  the deadline (configurable: `zatca.b2c.deadline_alert_hours`);
- marks the invoice as breached, records a CRITICAL error and fires `InvoiceReportingDeadlineBreached`
  if the deadline passes. Reporting continues afterwards.

## Repeated requests

Sending the same `invoice_number` (or `external_id`) again returns the **original** result with HTTP
status 200, including the same counter and QR. If the first request stopped before the document was
signed (for example the server crashed), the repeat completes it. A different payload under the same
number is refused with `DuplicateInvoice` (HTTP 409).

## Errors you should handle

| Exception | When | HTTP API |
|---|---|---|
| `DuplicateInvoice` | Same number, different payload | 409 |
| `DeviceUnavailable` | Device not onboarded, expired, suspended, or not registered for B2C | 503 |
| `InvalidArgumentException` | Missing device or invoice number | 422 |

Validation errors do not throw: `status` is `false` and `errors` lists them.
