# Key concepts

## Taxpayer, branch, device

- **Taxpayer (VAT registration)**: your company's VAT number, legal name and national address. The seller
  block on every invoice comes from here, never from the request.
- **Branch**: a location of the taxpayer. Optional for VAT groups.
- **Device (EGS unit)**: one invoicing unit, for example a POS terminal, an ERP instance or a web shop.
  Every device has its own private key, its own ZATCA certificates and its own invoice chain. A device
  must be **onboarded** before it can issue invoices.

## Environments

| Environment | Purpose |
|---|---|
| `sandbox` | ZATCA developer portal. Free testing; OTP `123345`. Nothing is legally reported. |
| `simulation` | ZATCA's pre-production environment with your real taxpayer data. |
| `production` | The real thing. |

A device belongs to exactly one environment. Counters and chains never mix between environments.

## Invoice counter (ICV) and previous invoice hash (PIH)

Each document a device issues gets:

- an **ICV**: 1, 2, 3, … per device, never reused, never reset;
- a **PIH**: the hash of the device's previous document (the first one uses a fixed value from ZATCA).

Together they form a **hash chain**. ZATCA can detect missing or altered documents from it. The package
allocates both under a database lock, so they stay correct even when many requests hit the same device.

::: tip Throughput
Because each document contains the previous document's hash, **one device issues one document at a time**.
To issue more invoices per second, use more devices (for example one per POS terminal or per worker pool).
See [Performance and scaling](./scaling).
:::

## Invoice record vs. issuance

The package separates your business record from the legal document:

| Concept | What it is | Changes on correction? |
|---|---|---|
| **Invoice record** | Your invoice: ID (`invoice_id`, a ULID), your `external_invoice_id`, invoice number, totals, statuses | Never. It is the same record for the whole life of the invoice. |
| **Issuance** | One legal document: UUID, ICV, PIH, hash, signature, QR, signed XML | Yes. A corrected document is a new issuance (n+1) under the same record. |

A record usually has one issuance. After a rejection and a correction it has two or more. All of them
stay visible in the history.

## Versions

The JSON you send is stored unchanged as **version 1**. A correction never edits it; it adds version 2,
3, … with the reason, the author and the list of changed fields.

## Idempotency

Every invoice has an `external_invoice_id` (by default your `invoice_number`). Sending the same ID again
returns the original result instead of creating a second invoice. That makes client retries safe, for
example after a network timeout. Sending a **different** payload under the same ID is refused.

## Outbox and queues

Submissions to ZATCA are never made inside your database transaction. The package writes an **outbox**
row together with the invoice, then pushes a job to the queue after the commit. If the queue is down, a
relay pushes it later. A committed invoice is therefore always submitted, exactly with the bytes that
were signed.

## Statuses

An invoice has several independent status fields (processing, validation, submission, reporting,
clearance). See [Statuses and lifecycle](./statuses).
