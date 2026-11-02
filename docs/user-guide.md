# User Guide

This guide covers what the package does **today** and what each upcoming milestone adds.
Nothing here describes features that do not exist yet as if they work; upcoming features are listed in
the [Roadmap](#roadmap) only.

- [Concepts](#concepts)
- [What you can use today](#what-you-can-use-today)
- [Devices: register, onboard, renew](#devices-register-onboard-renew)
- [Data model reference](#data-model-reference)
- [Validating ZATCA identifiers](#validating-zatca-identifiers)
- [Roadmap](#roadmap)

## Concepts

| Term | Meaning |
|---|---|
| **B2B workflow** | Standard tax invoices (and their credit/debit notes). Sent to ZATCA for **clearance** before you give them to the buyer. ZATCA returns a stamped "cleared" XML, which is the legal copy |
| **B2C workflow** | Simplified tax invoices (and notes). Given to the customer with a QR code at the time of sale, then **reported** to ZATCA (within 24 hours) |
| **Device (EGS unit)** | The invoicing solution unit registered with ZATCA. Each device has its own key, certificates (CSIDs), counter and hash chain |
| **CSID** | The certificate + secret ZATCA issues to a device. *Compliance* CSID for onboarding tests, *production* CSID for real invoices |
| **ICV** | Invoice counter: 1, 2, 3… per device, never reused |
| **PIH** | Previous invoice hash: links each document to the device's previous one, forming a chain |
| **Invoice record** | The package's internal record of your invoice. It never changes identity, even if the invoice is corrected |
| **Issuance** | One legal version of the invoice sent to ZATCA (its own UUID, ICV, hash, signature). A correction after rejection creates a new issuance under the same invoice record |

## What you can use today

After [installation](installation.md):

1. **The database schema** for companies, VAT registrations, branches, devices, certificates, invoices,
   versions, issuances, the hash-chain ledger, submissions, errors, warnings, audit and operations.
2. **Built-in safeguards** at the database level:
   - The same ICV can never be used twice on a device (`UNIQUE(device, environment, icv)`).
   - The hash chain cannot fork (each hash can precede only one document).
   - The same external invoice ID cannot be received twice for a company (safe retries from your app).
   - Original JSON, versions, issuances, chain entries and logs are **append-only**: the models refuse to
     update or delete them.
3. **Device management**: register taxpayers, branches and devices; onboard with ZATCA; renew
   certificates; expiry monitoring (see [Devices](#devices-register-onboard-renew)).
4. **Multi-tenancy** scoping (see [Configuration › Multi-tenancy](configuration.md#multi-tenancy)).
5. **Validation helpers** for ZATCA identifiers (below).
6. **Invoice engine** (low level): validate an invoice payload and produce signed XML + QR for B2B or B2C
   (see [Invoice engine](#invoice-engine)).
7. **Issuing and submitting invoices**: `Zatca::b2c()->issue()`, `Zatca::b2b()->submit()`, queues,
   retries and the optional HTTP API (see [Issuing invoices](#issuing-invoices)).

You can browse the data with the provided Eloquent models, for example:

```php
use CodingTriplets\Zatca\Laravel\Database\Models\Invoice;
use CodingTriplets\Zatca\Core\Domain\Enums\Workflow;
use CodingTriplets\Zatca\Core\Domain\Enums\ProcessingStatus;

$rejectedB2b = Invoice::query()
    ->where('workflow', Workflow::B2B)
    ->where('processing_status', ProcessingStatus::Rejected)
    ->orderByDesc('invoice_date')
    ->cursorPaginate(50);
```

> Do not write invoices, issuances or chain entries directly. Those rows are created by the issuing
> engine inside locked transactions; hand-written rows bypass the ICV/PIH guarantees.

## Devices: register, onboard, renew

Every invoicing unit (POS terminal, ERP instance, web shop) is a **device** with its own key,
certificates, counter and hash chain. A device must be onboarded with ZATCA before it can issue invoices.

### 1. Register the taxpayer and branch

```bash
php artisan zatca:taxpayer:register --name="Maximum Speed" --legal-name="Maximum Speed Tech Supply LTD" \
    --vat=399999999900003 --crn=1010010000 --branch-code=RYD --branch-name="Riyadh Branch" \
    --street="Prince Sultan" --building=2322 --district=Al-Murabba --city=Riyadh --postal-code=23333
```

The seller address (Saudi national address: 4-digit building number, 5-digit postal code) is printed on
every invoice and is required for the compliance checks during onboarding.

VAT groups (11th digit of the VAT number is `1`) also need `--group-tin=<10-digit member TIN>`. That TIN
replaces the branch name in the certificate request.

### 2. Register the device

```bash
php artisan zatca:device:register --vat-registration=1 --branch=1 --name=POS-RYD-01 \
    --types=1100 --industry="Supply activities" --location="RRRD2929"
```

`--types`: `1000` = B2B (standard invoices) only, `0100` = B2C (simplified) only, `1100` = both.
The EGS serial number is generated as `1-<provider>|2-<model>|3-<device uuid>` unless you pass `--serial`.

### 3. Onboard

Get a one-time password (OTP) from the Fatoora portal for the device's environment, then:

```bash
php artisan zatca:device:onboard 1
# OTP from the Fatoora portal (6 digits): ******
```

The OTP is typed as hidden input, sent straight to ZATCA, and never stored or logged. The package then:

1. Generates a secp256k1 key (stored encrypted) and the certificate signing request (CSR).
2. Requests the **compliance CSID**.
3. Runs ZATCA's **compliance checks**: it signs a sample invoice, credit note and debit note for each
   workflow the device is registered for (B2B and/or B2C) and submits them to ZATCA. These samples use
   their own counter and hash chain; your production chain is not touched. If a check fails, onboarding
   stops with status `AWAITING_COMPLIANCE_CHECKS` and the ZATCA errors are stored on the onboarding
   record. Fix the cause (usually the seller address) and continue with
   `php artisan zatca:device:onboard 1 --resume` (no new OTP needed).
4. Requests the **production CSID** and marks the device `ACTIVE`.

**Developer-portal sandbox:** its test OTP is `123345`. Compliance checks run there too. You can skip them
in the developer portal only with `ZATCA_SANDBOX_SKIP_COMPLIANCE_CHECKS=true`; this setting never applies
to the simulation or production environments.

From code:

```php
use CodingTriplets\Zatca\Laravel\Devices\DeviceOnboardingService;

$onboarding = app(DeviceOnboardingService::class)->onboard($device, $otp, actorId: auth()->id());
$onboarding->status; // COMPLETED | AWAITING_COMPLIANCE_CHECKS | FAILED (see ->error)
```

If ZATCA rejects a step (e.g. wrong or expired OTP), the onboarding is marked `FAILED` with ZATCA's
error code, and you can start again with a new OTP. Each attempt uses a fresh key; old keys are kept in history.

### 4. Renew before the certificate expires

```bash
php artisan zatca:device:renew 1     # asks for a new OTP
php artisan zatca:devices             # status and certificate expiry of every device
```

The device keeps issuing with its current certificate until the new one is stored. If renewal fails,
nothing changes and the device shows `RENEWAL_FAILED`. It can still issue until the old certificate expires.

A daily check (`zatca:devices:check-expiry`, registered with the Laravel scheduler at 03:15) moves
devices to `RENEWAL_DUE` within 30 days of expiry and to `EXPIRED` after it. It raises a
`CertificateExpiring` event once per threshold (30, 14, 7, 1 days). Listen to it to notify your team:

```php
Event::listen(\CodingTriplets\Zatca\Core\Events\CertificateExpiring::class, function ($e) {
    Notification::route('mail', 'finance@example.com')->notify(new CertificateExpiringNotice($e->deviceId, $e->daysLeft));
});
```

Other events: `DeviceKeyGenerated`, `ComplianceCsidIssued`, `DeviceOnboarded`, `DeviceOnboardingFailed`,
`CertificateRenewed`, `CertificateRenewalFailed`, `CertificateExpired` (namespace `CodingTriplets\Zatca\Core\Events`).
All fire only after the database transaction commits, and none contains secrets.

### Audit trail

Every registration, onboarding step and renewal is written to an append-only, hash-chained audit log.
`php artisan zatca:audit:verify` detects any modified or deleted entry.

### Security notes

- Private keys and CSID secrets are encrypted with envelope encryption (AES-256-GCM) under
  `ZATCA_ENCRYPTION_KEY`. Back this key up: without it, devices must be onboarded again.
- Key rotation: set the new key in `ZATCA_ENCRYPTION_KEY` and list old keys in
  `ZATCA_PREVIOUS_ENCRYPTION_KEYS` (comma-separated) so existing secrets stay readable.
- No command, page or API exports a private key (ZATCA prohibits exporting the stamping key).
- If your PHP has no CA bundle (common on Windows/WAMP), set `ZATCA_CA_BUNDLE=/path/to/cacert.pem`.
  Do not disable TLS verification.

## Invoice engine

M4 adds the document engine: validation → UBL 2.1 XML → hash → XAdES signature → QR code. B2B
(standard, `StandardInvoiceGenerator`) and B2C (simplified, `SimplifiedInvoiceGenerator`) are separate:
each one only accepts its own workflow and never converts one into the other.

> This is the low-level layer. From M5 on, the package allocates the invoice counter (ICV) and previous
> hash (PIH) under a device lock, stores the signed XML and submits it. Do not keep your own chain in
> production; use the M5/M6 API when it is available.

### Payload

```php
$payload = [
    'workflow'       => 'b2c',                     // b2b | b2c
    'type'           => 'invoice',                 // invoice | credit_note | debit_note
    'invoice_number' => 'INV-2026-000123',
    'issued_at'      => '2026-11-02T14:30:00',     // Asia/Riyadh if no offset is given
    'payment_means_code' => '10',                  // 10 cash, 30 credit, 42 bank, 48 card
    'seller' => [
        'name' => 'Maximum Speed Tech Supply LTD', 'vat_number' => '399999999900003',
        'id_scheme' => 'CRN', 'id_value' => '1010010000',
        'address' => ['street' => 'Prince Sultan', 'building_number' => '2322', 'district' => 'Al-Murabba',
                      'city' => 'Riyadh', 'postal_code' => '23333', 'country' => 'SA'],
    ],
    // Optional for B2C. For B2B: name, address, and vat_number or identification {scheme, value}.
    'buyer' => [],
    'lines' => [
        ['name' => 'Book', 'quantity' => '2', 'unit_price' => '30.00', 'discount' => '1.00',
         'vat_category' => 'S', 'vat_rate' => '15'],
        ['name' => 'Medicine', 'quantity' => '1', 'unit_price' => '25.00', 'vat_category' => 'Z', 'vat_rate' => '0',
         'exemption_reason_code' => 'VATEX-SA-35', 'exemption_reason' => 'Medicines and medical equipment'],
    ],
    'allowances' => [['amount' => '5.00', 'reason' => 'Loyalty discount', 'vat_category' => 'S', 'vat_rate' => '15']],
    'charges'    => [],
    // Credit / debit notes: 'billing_reference' => 'INV-2026-000100', 'reason' => 'Returned goods'
];
```

- Amounts are decimal strings (numbers are accepted). Prices exclude VAT. All maths is exact decimal
  arithmetic, rounded half-up to 2 decimals per VAT group.
- `vat_rate` is a percentage (`15`, not `0.15`).
- **The VAT category is required.** If it is missing and the rate is 15, `S` is assumed and a warning
  (`INFERRED`) is returned. A missing category with a 0% rate is an error, because 0% can mean zero-rated
  (`Z`), exempt (`E`) or out of scope (`O`). Z, E and O need a `VATEX-…` reason code and a reason text.
  A VAT breakdown is always written, also for single-line invoices (this avoids the BR-S-01 class of
  rejections).
- Advance payment adjustments (`prepaid_amount`) are not supported yet and are rejected.

### Validate and generate

```php
use CodingTriplets\Zatca\Core\Invoice\B2C\Generator\SimplifiedInvoiceGenerator;

$generator = new SimplifiedInvoiceGenerator();
$result = $generator->validate($payload);           // no counter used, nothing signed
$result->isValid();
$result->errors();   // [['field' => 'lines.0.vat_category', 'code' => 'BR-CL-18', 'message' => '...'], ...]
$result->warnings();
```

All problems are reported at once, each with the field path and the ZATCA rule ID where one applies.
`generate($payload, $chainPosition, $uuid, $signingCredentials)` returns the signed XML, invoice hash and
QR code, or throws `InvoiceValidationException` (with `->result`) before anything is signed.

### What was verified

- Hash, signature, certificate digest, signed-properties digest and QR layout reproduce ZATCA's own SDK
  samples byte-for-byte (`tests/Unit/Invoice/SdkGoldenTest.php`).
- Live on ZATCA's developer portal (2026-11-02): standard and simplified invoice, credit note and debit
  note, plus mixed S/Z/E/O categories, line discounts, document allowances and charges, and B2B buyers
  without a VAT number. All were accepted (CLEARED / REPORTED) with no errors.
- Local validation covers the rules that can be checked before signing. ZATCA applies the full rule set;
  its errors and warnings are returned as received.

## Issuing invoices

### B2C (simplified, reported)

```php
use CodingTriplets\Zatca\Laravel\ZatcaManager;

$result = app(ZatcaManager::class)->b2c()->issue([
    'invoice_number' => 'POS-000123',          // also the idempotency key (or pass options.external_id)
    'lines' => [
        ['name' => 'Coffee', 'quantity' => '2', 'unit_price' => '12.50', 'vat_category' => 'S', 'vat_rate' => '15'],
    ],
], ['device' => $deviceId]);

return response()->json($result->toArray(), $result->httpStatus);
```

With `zatca.b2c.reporting_mode = queue` (the default), the request makes no ZATCA call. It validates the
payload, takes the device's next ICV/PIH, signs, stores the XML and returns:

```json
{"status": true, "message": "invoice queued", "qr": "AR1NYXhp...", "counter": 10452,
 "uuid": "540ce75e-...", "invoice_id": "01M6E1Y8WX...", "invoice_hash": "I5/YMa...",
 "processing_status": "QUEUED", "reporting_deadline": "2026-11-03T14:57:29Z"}
```

Print `qr` on the receipt. Reporting runs on the `zatca-b2c-reporting` queue. When ZATCA answers, the invoice
moves to `REPORTED` (or `REPORTED_WITH_WARNINGS`), or to `REJECTED` with ZATCA's errors stored. Transient
failures (timeouts, 429, 5xx) are retried with the **same signed document**, within the 24-hour deadline.

- The seller block always comes from the device's registered taxpayer data. A `seller` sent by the client
  is replaced, and `uuid`, `icv`, `pih` and `invoice_hash` are ignored.
- Sending the same `invoice_number` again returns the original result (HTTP 200, same counter). A different
  payload under the same number is refused (`DuplicateInvoice`, HTTP 409). Nothing is overwritten.
- An invalid payload returns `status: false`, `message: "validation failed"` and field errors. No counter is used.

### B2B (standard, cleared)

```php
$result = app(ZatcaManager::class)->b2b()->submit($payload, ['device' => $deviceId]);   // returns at once
```

Generation and clearance run on the queues. Share a B2B invoice with the buyer **only once it is CLEARED**.
The cleared XML (stamped by ZATCA) is stored as artifact `cleared_xml`. `b2b()->issue()` also exists: it
issues in the request and queues clearance.

### Workers

```bash
php artisan queue:work --queue=zatca-b2c-reporting,zatca-b2c-retry,zatca-b2b-generation,zatca-b2b-clearance,zatca-b2b-retry
php artisan zatca:outbox:relay --daemon      # low-latency relay (the scheduler also runs it every minute)
```

Every submission is written to an outbox row in the same transaction as the invoice, then pushed to the
queue after commit. If the push fails (Redis down, process killed), the relay pushes it later, so a committed
invoice is always submitted. `zatca:chain:verify [--deep]` checks every device chain: ICVs are contiguous,
each PIH equals the previous hash, and the stored XML re-hashes to the ledger value.

### HTTP API (optional)

Set `ZATCA_API_ENABLED=true` and put your auth middleware in `zatca.api.middleware`.

| Method | Path | Notes |
|---|---|---|
| POST | `/api/zatca/b2c/invoices` | Body = payload. Headers: `X-Zatca-Device` (device ID or UUID), optional `Idempotency-Key`. 201 new, 200 replay, 409 different payload, 422 invalid |
| POST | `/api/zatca/b2b/invoices` | Same, for B2B |
| GET | `/api/zatca/invoices/{invoice_id}` | Current state |

Under overload, the API answers `429` (too many concurrent requests) or `503` (queue backlog above
`queues.high_water_mark`) with `Retry-After`. Requests are not left to pile up until a gateway answers 502.

### Throughput and latency

- One device's chain is strictly sequential, because each document embeds the previous hash. Signing takes
  about 4 ms. Total request time depends mostly on database commit latency: about 89 ms warm on SQLite under
  Windows, and expected to be much lower on MySQL/PostgreSQL on Linux (⚠ to be benchmarked in M8).
- Throughput scales with the number of devices (POS terminals, or several EGS units per branch), database
  write capacity and queue workers. Very high rates (100k+ invoices per second) need many devices, a sharded
  database and a horizontally scaled web tier. The package takes the ZATCA round trip out of the request; it
  cannot remove those other limits.

## Data model reference

| Model | Table | Purpose |
|---|---|---|
| `Company` | `zatca_companies` | Your legal entity |
| `VatRegistration` | `zatca_vat_registrations` | VAT number (incl. VAT-group member TIN) and seller ID |
| `Branch` | `zatca_branches` | Branch / location |
| `Device` | `zatca_devices` | EGS unit, invoice-type flags (`1000` B2B, `0100` B2C, `1100` both), lifecycle |
| `DeviceKey` | `zatca_device_keys` | Encrypted private key + CSR (key is hidden from serialization) |
| `Certificate` | `zatca_certificates` | Compliance/production CSIDs, versions, validity (secret hidden) |
| `DeviceOnboarding` / `DeviceRenewal` | … | Onboarding and renewal history |
| `InvoiceCounter` | `zatca_invoice_counters` | Chain head (last ICV + hash) per device and environment |
| `Invoice` | `zatca_invoices` | Internal invoice record + all status dimensions |
| `InvoiceVersion` | `zatca_invoice_versions` | Original JSON (v1) and every correction |
| `InvoiceDraft` | `zatca_invoice_drafts` | Unsaved editor drafts |
| `InvoiceIssuance` | `zatca_invoice_issuances` | Each legal issuance (UUID, ICV, hash, signature, QR) |
| `ChainEntry` | `zatca_chain_entries` | Hash-chain ledger |
| `InvoiceArtifact` | `zatca_invoice_artifacts` | Pointers to signed / cleared XML on the private disk |
| `B2bClearanceSubmission` / `B2cReportingSubmission` | … | Submission state per issuance |
| `SubmissionAttempt` | `zatca_submission_attempts` | Every HTTP call to ZATCA (API log) |
| `ZatcaError`, `ErrorGroup`, `Warning` | … | Errors (grouped) and warnings |
| `ProcessingLog`, `AuditLog` | … | Status history and audit trail |
| `OutboxMessage`, `BulkOperation`, `DailyStat`, `Setting` | … | Operations |

### Status dimensions on an invoice

| Column | Meaning |
|---|---|
| `processing_status` | Where the invoice is in its workflow (RECEIVED … REPORTED / CLEARED / REJECTED … COMPLETED) |
| `validation_status` | Local validation result |
| `submission_status` | Only the HTTP exchange (delivered, failed, unknown) – **not** acceptance |
| `reporting_status` | B2C outcome (REPORTED / NOT_REPORTED…); `NOT_APPLICABLE` for B2B |
| `clearance_status` | B2B outcome (CLEARED / NOT_CLEARED…); `NOT_APPLICABLE` for B2C |
| `zatca_response_status` | Raw status string returned by ZATCA |

## Validating ZATCA identifiers

The core value objects implement ZATCA format rules and can be used in your own forms:

```php
use CodingTriplets\Zatca\Core\Domain\ValueObjects\VatNumber;
use CodingTriplets\Zatca\Core\Domain\ValueObjects\BuyerIdentification;
use CodingTriplets\Zatca\Core\Domain\ValueObjects\InvoiceTypeFlags;
use CodingTriplets\Zatca\Core\Domain\Exceptions\InvalidValue;

VatNumber::isValid('399999999900003');                 // true: 15 digits, starts and ends with 3
VatNumber::fromString('300000000010003')->isGroup();   // true: 11th digit is 1 (VAT group)

// B2B buyer without VAT/CR: identify by national ID, Iqama, passport, ...
$buyerId = BuyerIdentification::of('NAT', '1012345678');

// Several IDs known? The package writes one, using ZATCA's order (TIN, CRN, MOM, MLS, 700, SAG, NAT, GCC, IQA, PAS, OTH)
$chosen = BuyerIdentification::preferred([
    BuyerIdentification::of('PAS', 'A1234567'),
    BuyerIdentification::of('NAT', '1012345678'),
]);                                                     // NAT

InvoiceTypeFlags::fromString('1100')->workflows();     // [Workflow::B2B, Workflow::B2C]

try {
    BuyerIdentification::of('NAT', '1012-345');
} catch (InvalidValue $e) {
    echo $e->getMessage(); // [BR-KSA-14] buyer.identification.value: must be non-empty and alphanumeric only
}
```

## Roadmap

| Milestone | Adds | Status |
|---|---|---|
| M2 Foundation | Schema, models, configuration, core types, compatibility CI | **Done** |
| M3 Devices | Encrypted key vault, CSR generation, onboarding (compliance CSID → compliance checks → production CSID), renewal, expiry alerts, audit trail | **Done** |
| M4 Invoice engine | B2B and B2C XML generation, hashing, signing, QR, compliance checks, validated against ZATCA's SDK | **Done (0.3.0-dev)** |
| M5 Processing | Atomic ICV/PIH issuing, immutable XML storage, outbox, queues, retries, circuit breaker, error engine, B2C reporting and B2B clearance, HTTP API | **Done (0.4.0-dev)** |
| M6 ZATCA workflows | Correct-and-reprocess, credit/debit note operations, 303 handling, reconciliation, deadline monitoring | Next |
| M7 Dashboard | `/admin/zatca` console: dashboards, B2B/B2C lists, JSON correction editor, devices, errors, logs, reports, permissions | Planned |
| M8 Release | Performance testing, security review, full documentation, 1.0.0 | Planned |

Issuing, reporting and clearance work end to end in the ZATCA developer sandbox. Corrections of rejected
invoices, credit/debit note operations and the dashboard follow in M6–M7. Do not use in production before 1.0.
