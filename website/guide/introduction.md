# Introduction

**Laravel ZATCA** connects a Laravel application to ZATCA's e-invoicing platform (Fatoora), Phase 2
("integration phase"). It turns your invoice data (JSON / PHP arrays) into signed UBL 2.1 XML, sends it
to ZATCA and keeps a complete, auditable record of everything that happened to each invoice.

## What the package does for you

| Task | Done by the package |
|---|---|
| Onboard each invoicing device (POS, ERP, web shop) with ZATCA | Key generation, CSR, compliance CSID, compliance checks, production CSID, renewal |
| Validate invoice data before anything is signed | ZATCA business rules with field paths and rule IDs |
| Build the legal document | UBL 2.1 XML, invoice hash, XAdES signature, QR code |
| Keep the invoice chain correct | Invoice counter (ICV) and previous invoice hash (PIH) per device, safe under concurrency |
| Talk to ZATCA | Reporting (B2C), clearance (B2B), retries, rate limiting, circuit breaker |
| Handle problems | Rejection details, retry, correction with a new issuance, credit/debit notes, deadline alerts |
| Keep history | Original JSON, every version, every issuance, every API attempt, errors, logs, audit trail |

## B2B and B2C

ZATCA distinguishes two kinds of tax invoices. The package keeps them completely separate: each has its
own API, queues, submission records and rules.

| | **B2C** (simplified tax invoice) | **B2B** (standard tax invoice) |
|---|---|---|
| Typical buyer | Consumer (shop, restaurant, POS) | Business (VAT-registered buyer, government) |
| ZATCA process | **Reporting** within 24 hours after the sale | **Clearance** before the invoice is given to the buyer |
| Customer receives | Your document with a QR code, immediately | The **cleared** XML, stamped by ZATCA |
| Package API | `Zatca::b2c()->issue()` | `Zatca::b2b()->issue()` or `->submit()` |
| Buyer details | Optional | Required (name, address, VAT number or other ID) |

## How an invoice flows

```
Your app ──JSON──▶ validate ──▶ next counter + hash ──▶ sign XML + QR ──▶ store ──▶ queue
                                                                               │
                                     ZATCA ◀── report (B2C) / clear (B2B) ◀────┘
                                       │
                         accepted / rejected / temporary failure ──▶ saved on the invoice + event
```

1. Your code sends the invoice data.
2. The package validates it. Invalid data never uses a counter.
3. Inside a database transaction it takes the device's next counter and previous hash, signs the
   document and stores the XML.
4. A queue worker sends it to ZATCA and records the result: accepted, accepted with warnings, rejected
   (with ZATCA's error messages) or a temporary failure (retried automatically).
5. You are told about the outcome through statuses, events and (optionally) the HTTP API.

## What it does not do

- It is not an accounting system. It does not compute your prices or manage stock; you send the final
  invoice lines.
- It does not decide tax treatment for you. You choose the VAT category of each line.
- It does not print receipts or PDFs (yet). You get the QR string and the XML; render them in your app.

## Next steps

- [Key concepts](./concepts): the terms used throughout this guide.
- [Quick start](./quick-start): issue and report a first invoice in the ZATCA sandbox.
