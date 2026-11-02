# Features at a glance

Everything the package does, with the page that explains it. ⚠ marks behaviour that depends on a ZATCA
rule not yet confirmed in official documentation.

## Setup

| Feature | How | Docs |
|---|---|---|
| Companies (taxpayers) and VAT registrations | `Zatca::companies()->create()`, `zatca:taxpayer:register` | [Devices from code](./device-import#_1-companies-and-stores), [Taxpayers and devices](./devices) |
| Stores / branches | `Zatca::companies()->createStore()`, `stores` in `create()` | [Devices from code](./device-import) |
| Your own IDs for companies and stores | `external_ref` | [Devices from code](./device-import#_2-the-device-file) |
| Devices (EGS units), one or many | `Zatca::devices()->register()/registerMany()`, `zatca:device:register` | [Devices from code](./device-import) |
| Device import from CSV / Excel, local or S3 | `Zatca::devices()->import()`, `zatca:devices:import` | [Devices from code](./device-import) |
| Dry-run validation of a device file | `validateMany()`, `--validate` | [Devices from code](./device-import#_3-register) |
| Onboarding (key, CSR, compliance CSID, compliance checks, production CSID) | `onboard()`, `onboardMany()`, OTP column, `zatca:device:onboard` | [Taxpayers and devices](./devices#_3-onboard) |
| Resume after failed compliance checks | `resume()`, `--resume` | [Taxpayers and devices](./devices) |
| Certificate renewal and expiry alerts | `zatca:device:renew`, `zatca:devices:check-expiry` | [Taxpayers and devices](./devices#_4-certificate-renewal) |
| Sandbox, simulation and production | `ZATCA_ENVIRONMENT`, per device | [Going live](./going-live) |

## Invoicing

| Feature | How | Docs |
|---|---|---|
| B2C simplified invoices, reported within 24 h | `Zatca::b2c()->issue()` | [B2C](./b2c) |
| B2B standard invoices, cleared before delivery | `Zatca::b2b()->issue()/submit()` | [B2B](./b2b) |
| Validation only | `validate()` | [Invoice payload](./payload#validation) |
| Idempotency (same number = same result) | `external_id` / `invoice_number` | [B2C](./b2c), [HTTP API](./http-api) |
| Unit, line and header discounts; percentages; split over VAT groups | `discount`, `discounts[]`, `allowances[]`, `distribute` | [Discounts](./payload#discounts) |
| Charges (delivery etc.) | `charges[]` | [Invoice payload](./payload#header-document-discounts-and-charges) |
| Zero-rated, exempt, out-of-scope lines | `vat_category`, `exemption_reason_code` | [Invoice payload](./payload#vat-exemption-codes) |
| QR code (TLV, phase 2) | `qr` in the result | [B2C](./b2c) |
| Cleared XML for the buyer | stored artifact | [B2B](./b2b#getting-the-cleared-invoice) |
| Stored lines and VAT breakdown | `$invoice->lines`, `vatBreakdown` | [Your own invoice tables](./host-models#line-items) |
| Link to your own invoice/order table | `source` option, `HasZatcaInvoices` | [Your own invoice tables](./host-models) |

## After issuing

| Feature | How | Docs |
|---|---|---|
| Automatic retries with backoff | workers | [Rejections, retries and corrections](./corrections) |
| Manual retry of the identical document | `retry()`, `zatca:invoice retry` | [Corrections](./corrections#a-retry-the-submission) |
| Correct a rejected or invalid invoice | `correct()`, `zatca:invoice correct` | [Corrections](./corrections#b-correct-and-reprocess) |
| Reissue unchanged | `reissueUnchanged()`, `zatca:invoice reissue` | [Corrections](./corrections#reissue-without-changes) |
| Four-eyes approval of corrections | `approve()`, `zatca:invoice approve` | [Corrections](./corrections#approval-four-eyes) |
| Credit and debit notes, credit limit | `creditNote()`, `debitNote()`, `zatca:invoice credit/debit` | [Credit and debit notes](./notes) |
| Cancel an accepted invoice (full credit note) | `cancel()`, `zatca:invoice cancel` | [Cancelling invoices](./cancellation) |
| Discard a never-signed invoice | `discard()`, `zatca:invoice discard` | [Cancelling invoices](./cancellation#discard-an-invoice-that-was-never-signed) |
| Abandon a rejected invoice ⚠ | `abandon()`, `zatca:invoice abandon` | [Cancelling invoices](./cancellation#abandon-a-rejected-invoice) |
| 303 "clearance switched off" handling ⚠ | automatic | [B2B](./b2b#clearance-switched-off-303) |
| 24-hour reporting deadline alerts | `zatca:deadlines:check` | [Monitoring](./monitoring) |
| Statuses and history of every change | `processing_status`, history tables | [Statuses and lifecycle](./statuses) |

## Integration

| Feature | How | Docs |
|---|---|---|
| PHP facade | `Zatca::…` | [PHP API](/reference/php-api) |
| HTTP API (optional) | `ZATCA_API_ENABLED` | [HTTP API](./http-api) |
| Events for every outcome | `InvoiceAccepted`, `InvoiceRejected`, `InvoiceCancelled`, … | [Events](./events) |
| Multi-company and multi-tenant | tenant resolver | [Multi-company and multi-tenant](./tenancy) |
| Queues, workers, scheduler | `queue:work`, outbox relay | [Queues and workers](./workers) |
| Web dashboard: overview, B2B/B2C lists, invoice detail, actions, XML download, permissions | `/admin/zatca`, `Zatca::auth()` | [Dashboard](./dashboard) |
| Audit trail of every operator action (hash-chained) | `zatca:audit:verify` | [Security](./security) |

## Reliability and security

| Feature | Docs |
|---|---|
| Gap-free invoice counter (ICV) and hash chain (PIH) per device, safe under concurrency | [Key concepts](./concepts), [Scaling](./scaling) |
| Chain and audit verification | `zatca:chain:verify`, `zatca:audit:verify` — [Monitoring](./monitoring) |
| Lost-message recovery | `zatca:reconcile` — [Monitoring](./monitoring) |
| Load shedding (`429`/`503`) | [HTTP API](./http-api#load-shedding), [Scaling](./scaling) |
| Encrypted private keys and secrets, OTPs never stored, redacted logs, TLS verification, XXE protection | [Security](./security) |
| Laravel 9–13, PHP 8.1+, MySQL/MariaDB/PostgreSQL/SQLite | [Requirements](./requirements), [Compatibility](/reference/compatibility) |

## Not available yet

| Feature | Planned |
|---|---|
| Dashboard: devices and bulk import, errors, queue, audit, settings | M7b |
| Dashboard: correction editor, note forms, charts, reports and exports | M7c |
| Batch HTTP endpoint, per-device in-flight limit | later; the config keys exist but are not used yet |
| Prepaid amounts, currencies other than SAR | later |
