# Cancelling invoices

ZATCA has no "delete" or "void". What you can do depends on how far the invoice got:

| Situation | Use | Result |
|---|---|---|
| Accepted by ZATCA (`REPORTED` / `CLEARED`) | `cancel()` | A full credit note is issued; the original stays valid but is fully credited |
| Never signed (invalid JSON, waiting, failed before signing) | `discard()` | Status `CANCELLED`; no counter, UUID or hash was used |
| Rejected by ZATCA and you won't fix it | `abandon()` ⚠ | Status `ABANDONED`; the rejected document stays in the history |

All three need a reason, are recorded in the invoice history and the hash-chained audit log, and fire
`InvoiceCancelled`. They are also available in the [dashboard](./dashboard).

## Cancel an accepted invoice

```php
use CodingTriplets\Zatca\Laravel\Facades\Zatca;

$result = Zatca::invoices()->cancel($invoiceId, 'Order cancelled by the customer', auth()->id());
// $result is an IssueResult for the credit note (qr, counter, uuid, invoice_id …)
```

The credit note copies the lines, discounts, header allowances and charges of the original, so its total
equals the original's. Its number is `{original number}-CN` unless you pass
`options: ['invoice_number' => 'CN-2026-0042']`. It is reported or cleared like any other document.

`cancel()` is refused when the invoice is already cancelled or already (partly) credited — for the rest
of a partly credited invoice, issue a [credit note](./notes) for the remaining amount
(`$invoice->creditableAmount()`).

After the call: `$invoice->cancellation_invoice_id` points to the credit note,
`$invoice->isFullyCredited()` is `true`.

## Discard an invoice that was never signed

```php
Zatca::invoices()->discard($invoiceId, 'Entered twice', auth()->id());
```

Allowed in `VALIDATION_FAILED`, `READY_FOR_PROCESSING`, `FAILED` and `MANUAL_REVIEW`, and only when no
legal document (issuance) exists. The invoice gets status `CANCELLED` and `closed_at`; nothing is sent to
ZATCA, and queued work for it is skipped.

## Abandon a rejected invoice ⚠ {#abandon-a-rejected-invoice}

```php
Zatca::invoices()->abandon($invoiceId, 'Sale reversed in the POS', auth()->id());
```

Allowed in `REJECTED` and `MANUAL_REVIEW` when the current document was rejected. The open errors are
marked resolved with your reason, and the invoice gets status `ABANDONED`. The rejected document keeps its
place in the device's chain (its counter and hash are never reused).

::: warning ⚠ Confirm with your tax adviser
A rejected document is not a valid tax invoice. Whether a rejected sale may simply be abandoned instead of
being corrected and reissued is a business and compliance decision; the package records it but cannot make
it for you.
:::

Abandoning a rejected **credit note** frees the amount it had reserved on the original invoice.

## After closing

`CANCELLED` and `ABANDONED` are final: retry, correct, reissue and approve are refused.

```php
use CodingTriplets\Zatca\Core\Events\InvoiceCancelled;

Event::listen(function (InvoiceCancelled $e) {
    // $e->kind: credit_note | discarded | abandoned
    // $e->cancellationPublicId: the credit note (kind credit_note)
    // $e->sourceType / $e->sourceId: your own record, if linked
});
