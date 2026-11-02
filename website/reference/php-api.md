# PHP API

Everything is reachable through the `Zatca` facade (`CodingTriplets\Zatca\Laravel\Facades\Zatca`).

## Issuing — `Zatca::b2c()` / `Zatca::b2b()`

Both return an `InvoiceIssuer` bound to one workflow. A B2C issuer never accepts B2B data and vice versa.

| Method | Returns | Description |
|---|---|---|
| `issue(array $payload, array $options = [])` | `IssueResult` | Validate, sign, queue submission |
| `submit(array $payload, array $options = [])` | `IssueResult` | Validate and store; signing and submission on the queue (intended for B2B) |
| `validate(array $payload, array $options = [])` | `ValidationResult` | Validation only; nothing stored |
| `submitNow(string $invoiceId)` | `string` | Submit a queued invoice inside this call; returns the submission status |

**Options**

| Key | Description |
|---|---|
| `device` | Device ID or UUID (required) |
| `external_id` | Idempotency key (default: `invoice_number`) |
| `actor_id` | User ID recorded in the history |

## `IssueResult`

| Property / method | Description |
|---|---|
| `toArray()` | The JSON contract: `status`, `message`, `qr`, `counter`, `uuid`, `invoice_id`, `invoice_hash`, `processing_status`, `reporting_deadline`, `errors` |
| `httpStatus` | Suggested HTTP status (201, 200, 422) |

## Operations — `Zatca::invoices()`

`$invoice` is the `invoice_id` (string) or an `Invoice` model.

| Method | Returns | Description |
|---|---|---|
| `retry($invoice, ?int $actorId = null, bool $now = false)` | `string` | Resend the identical document |
| `correct($invoice, array $payload, string $reason, ?int $actorId = null)` | `IssueResult` | New version + new issuance |
| `reissueUnchanged($invoice, string $reason, ?int $actorId = null)` | `IssueResult` | New issuance from the current JSON |
| `approve($invoice, int $approverId)` | `IssueResult` | Approve a held correction |
| `creditNote($original, array $payload, string $reason, ?int $actorId = null, array $options = [])` | `IssueResult` | Credit note (381) |
| `debitNote($original, array $payload, string $reason, ?int $actorId = null, array $options = [])` | `IssueResult` | Debit note (383) |

## Exceptions

| Exception | Namespace | When |
|---|---|---|
| `DuplicateInvoice` | `Laravel\Invoices` | Same idempotency key, different payload (`->publicId`) |
| `DeviceUnavailable` | `Laravel\Invoices` | Device cannot issue for this workflow |
| `OperationNotAllowed` | `Laravel\Invoices` | Operation does not fit the invoice's state |
| `InvoiceValidationException` | `Core\Invoice\Shared\Validation` | Invalid correction (`->result`) |

## Models

`CodingTriplets\Zatca\Laravel\Database\Models\`: `Invoice`, `InvoiceVersion`, `InvoiceIssuance`,
`InvoiceArtifact`, `Device`, `Certificate`, `VatRegistration`, `ZatcaError`, `Warning`, … Read them
freely; **do not write** counters, issuances or chain entries yourself.

## Other

| Method | Description |
|---|---|
| `Zatca::version()` | Package version |
| `Zatca::defaultEnvironment()` | `Environment` enum from the configuration |
| `DeviceOnboardingService::onboard($device, $otp, actorId:)` | Onboarding from code |
| `CertificateRenewalService` | Renewal from code |
