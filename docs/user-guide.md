# User Guide

This guide covers what the package does **today** and what each upcoming milestone adds.
Nothing here describes features that do not exist yet as if they work; upcoming features are listed in
the [Roadmap](#roadmap) only.

- [Concepts](#concepts)
- [What you can use today](#what-you-can-use-today)
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
3. **Multi-tenancy** scoping (see [Configuration › Multi-tenancy](configuration.md#multi-tenancy)).
4. **Validation helpers** for ZATCA identifiers (below).

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
> engine (milestones M5–M6) inside locked transactions; hand-written rows bypass the ICV/PIH guarantees.

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
| M2 Foundation | Schema, models, configuration, core types, compatibility CI | **Done (0.1.0-dev)** |
| M3 Devices | Encrypted key vault, CSR generation, onboarding (compliance CSID → compliance checks → production CSID), renewal, expiry alerts | Next |
| M4 Invoice engine | B2B and B2C XML generation, hashing, signing, QR, validated against ZATCA's SDK | Planned |
| M5 Processing | Atomic ICV/PIH issuing, immutable XML storage, outbox, queues, retries, error engine, audit | Planned |
| M6 ZATCA workflows | B2B clearance, B2C reporting, retry / correct-and-reprocess / credit-debit note operations | Planned |
| M7 Dashboard | `/admin/zatca` console: dashboards, B2B/B2C lists, JSON correction editor, devices, errors, logs, reports, permissions | Planned |
| M8 Release | Performance testing, security review, full documentation, 1.0.0 | Planned |

**You cannot issue or submit invoices to ZATCA with this version.** The first version able to do so
end-to-end in the ZATCA sandbox is expected at the end of M6.
