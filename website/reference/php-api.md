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
| `source` | Your own record: an Eloquent model or `['type' => …, 'id' => …]` ([details](/guide/host-models)) |

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
| `cancel($original, string $reason, ?int $actorId = null, array $options = [])` | `IssueResult` | Full credit note for an accepted invoice |
| `discard($invoice, string $reason, ?int $actorId = null)` | `Invoice` | Close an invoice that was never signed (`CANCELLED`) |
| `abandon($invoice, string $reason, ?int $actorId = null)` | `Invoice` | Close a rejected invoice (`ABANDONED`) ⚠ |

## Companies — `Zatca::companies()`

| Method | Returns | Description |
|---|---|---|
| `create(array $data, ?int $actorId = null)` | `Company` | Company + VAT registration (+ `stores`), idempotent by `external_ref` / VAT number |
| `createStore($company, array $data)` | `Branch` | Store (`code`, `name`, `address?`, `external_ref?`), idempotent |
| `resolveCompany($id, string $ids = 'auto')` | `Company` | By `external_ref` or package ID |
| `resolveStore(Company $company, $id, string $ids = 'auto')` | `Branch` | By `external_ref`, code or package ID |

## Devices — `Zatca::devices()`

| Method | Returns | Description |
|---|---|---|
| `register(array $row, ?int $actorId = null, array $options = [])` | `Device` | One device |
| `registerMany(iterable $rows, array $options = [])` | `DeviceImportReport` | Many devices (onboards rows with `otp`) |
| `import(string\|array $file, array $options = [])` | `DeviceImportReport` | From a CSV/XLSX path or `['disk', 'path']` |
| `validateMany(iterable\|string\|array $rows, array $options = [])` | `DeviceImportReport` | Check only |
| `onboard($device, string $otp, ?int $actorId = null)` | `DeviceOnboarding` | One device |
| `onboardMany(iterable $items, ?int $actorId = null)` | `DeviceImportReport` | `[['device' => …, 'otp' => …], …]` |
| `resume($device, ?int $actorId = null)` | `DeviceOnboarding` | Continue after compliance checks |

Options: `ids` (`auto` \| `external` \| `package`), `onboard` (default `true`), `actor_id`. See
[Devices from code and Excel](/guide/device-import).

## Exceptions

| Exception | Namespace | When |
|---|---|---|
| `DuplicateInvoice` | `Laravel\Invoices` | Same idempotency key, different payload (`->publicId`) |
| `DeviceUnavailable` | `Laravel\Invoices` | Device cannot issue for this workflow |
| `OperationNotAllowed` | `Laravel\Invoices` | Operation does not fit the invoice's state |
| `InvoiceValidationException` | `Core\Invoice\Shared\Validation` | Invalid correction (`->result`) |

## Models

`CodingTriplets\Zatca\Laravel\Database\Models\`: `Invoice`, `InvoiceVersion`, `InvoiceIssuance`,
`InvoiceArtifact`, `InvoiceLine`, `InvoiceVatBreakdown`, `Device`, `Certificate`, `VatRegistration`,
`ZatcaError`, `Warning`, … Read them
freely; **do not write** counters, issuances or chain entries yourself.

## Other

| Method | Description |
|---|---|
| `Zatca::version()` | Package version |
| `Zatca::defaultEnvironment()` | `Environment` enum from the configuration |
| `DeviceOnboardingService::onboard($device, $otp, actorId:)` | Onboarding from code |
| `CertificateRenewalService` | Renewal from code |
