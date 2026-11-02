# Credit and debit notes

An invoice accepted by ZATCA (`REPORTED` or `CLEARED`) is final. To cancel or change it, issue a note
that references it:

| Note | Type code | Use for |
|---|---|---|
| Credit note | 381 | Cancel the invoice (full amount) or reduce it (returns, discounts after the sale, price errors) |
| Debit note | 383 | Increase the amount (additional charges, under-billing) |

A note is a new document with its own record, number, counter and QR, in the **same workflow** as the
original: a B2C invoice gets a B2C note (reported), a B2B invoice a B2B note (cleared).

## Issue a credit note

```php
use CodingTriplets\Zatca\Laravel\Facades\Zatca;

$result = Zatca::invoices()->creditNote($originalInvoiceId, [
    'invoice_number' => 'CN-2026-0007',
    'lines' => [
        ['name' => 'Coffee (returned)', 'quantity' => '1', 'unit_price' => '12.50', 'vat_category' => 'S', 'vat_rate' => '15'],
    ],
], reason: 'Item returned by the customer', actorId: auth()->id());
```

To cancel the whole invoice, repeat all of its lines in the credit note.

## Issue a debit note

```php
Zatca::invoices()->debitNote($originalInvoiceId, [
    'invoice_number' => 'DN-2026-0003',
    'lines' => [
        ['name' => 'Additional delivery charge', 'quantity' => '1', 'unit_price' => '25.00', 'vat_category' => 'S', 'vat_rate' => '15'],
    ],
], reason: 'Delivery charge not billed');
```

## What the package fills in

| Field | Value |
|---|---|
| `type` | `credit_note` / `debit_note` |
| `billing_reference` | The original's invoice number |
| `reason` | Your `reason` argument (required) |
| `buyer`, `supply_date`, `subtype` | Taken from the original unless you send them |
| Device | The original's device, unless you pass `options: ['device' => …]` |

The note record is linked to the original (`billing_reference_invoice_id`), so you can list all notes of
an invoice.

## Rules

- The original must be an **invoice** (not a note) and must be **accepted** by ZATCA. For a rejected
  invoice, [correct it](./corrections#b-correct-and-reprocess) instead.
- The note has the same idempotency as an invoice: sending the same note number again returns the first result.
- The result has the same shape as for invoices (`qr`, `counter`, `uuid`, `invoice_id`, …).
