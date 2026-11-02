# B2B: standard invoices

Standard tax invoices (business customers) must be **cleared** by ZATCA before they are given to the
buyer. ZATCA returns the cleared invoice, which carries ZATCA's stamp.

::: warning Share only cleared invoices
Do not send a standard invoice to the buyer before its status is `CLEARED`. Send the **cleared XML**
returned by ZATCA (stored by the package as artifact `cleared_xml`), not your own signed XML.
:::

## Two ways to send

| Method | What happens in your request | Use when |
|---|---|---|
| `Zatca::b2b()->issue($payload, $options)` | Validate, sign (counter assigned), queue clearance | You want the counter and UUID immediately |
| `Zatca::b2b()->submit($payload, $options)` | Validate and store only; signing and clearance run on the queue | Bulk / ERP batches; fastest response |

```php
use CodingTriplets\Zatca\Laravel\Facades\Zatca;

$result = Zatca::b2b()->submit($payload, ['device' => $deviceId]);
$result->toArray();
// {"status": true, "message": "invoice accepted for processing", "invoice_id": "01M6...", "processing_status": "READY_FOR_PROCESSING"}
```

Options and idempotency are the same as for [B2C](./b2c#issue-an-invoice).

## After clearance

| ZATCA result | `processing_status` | `clearance_status` | Event |
|---|---|---|---|
| Cleared | `CLEARED` | `CLEARED` | `InvoiceAccepted` |
| Cleared with warnings | `CLEARED` | `CLEARED_WITH_WARNINGS` | `InvoiceAccepted` |
| Not cleared | `REJECTED` | `NOT_CLEARED` | `InvoiceRejected` |
| Clearance switched off (HTTP 303) | `QUEUED` → `REPORTED` | `CLEARANCE_DEACTIVATED` | `ClearanceDeactivated` |
| Temporary failure | `RETRY_PENDING` | `PENDING` | (retried automatically) |

## Getting the cleared invoice

```php
use CodingTriplets\Zatca\Core\Contracts\Storage\ArtifactStore;
use CodingTriplets\Zatca\Core\Contracts\Storage\StoredArtifact;
use CodingTriplets\Zatca\Core\Domain\Enums\ArtifactKind;
use CodingTriplets\Zatca\Laravel\Database\Models\InvoiceArtifact;

$artifact = InvoiceArtifact::where('issuance_id', $invoice->current_issuance_id)
    ->where('kind', ArtifactKind::ClearedXml)->firstOrFail();

$xml = app(ArtifactStore::class)->get(new StoredArtifact(
    $artifact->disk, $artifact->path, $artifact->sha256, (int) $artifact->bytes, (bool) $artifact->encrypted,
));
```

The store checks the SHA-256 of the file on every read; a modified file raises an error instead of being
returned.

A convenient place to deliver the invoice is a listener on `InvoiceAccepted`:

```php
Event::listen(InvoiceAccepted::class, function (InvoiceAccepted $e) {
    if ($e->workflow === 'b2b') {
        SendClearedInvoiceToBuyer::dispatch($e->invoiceId);
    }
});
```

## Clearance switched off (303)

ZATCA can temporarily switch clearance off and answer HTTP 303. By default
(`zatca.b2b.on_clearance_deactivated = report`) the package then sends the **same signed document** to
the reporting API, and the invoice ends as `REPORTED` with `clearance_status = CLEARANCE_DEACTIVATED`.
Set it to `manual_review` to park such invoices until an operator retries them.

::: info
This behaviour follows ZATCA's API description of the 303 answer; it has not been observed live yet
(the sandbox does not switch clearance off).
:::

## Buyer requirements

See [Invoice payload → Buyer](./payload#buyer). In short: name, Saudi address, and a VAT number or
another ID. Export invoices (`subtype.export = true`) must not contain a buyer VAT number.
