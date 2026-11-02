# Your own invoice tables

You keep your own invoice table (`sales`, `bills`, `pos_orders` …) with any name and structure. The package
stores its own records next to it and links them to yours.

## Link an invoice to your record

Pass `source` when issuing:

```php
$result = Zatca::b2c()->issue($payload, ['device' => $deviceId, 'source' => $order]);   // an Eloquent model
$result = Zatca::b2c()->issue($payload, ['device' => $deviceId, 'source' => ['type' => 'pos_order', 'id' => 'A-1009']]);
```

The link is stored as `source_type` / `source_id` on the package's invoice (a polymorphic relation, so
`source_id` may be a number, UUID or string). Credit and debit notes (including `cancel()`) get the link of the original unless you pass `source` for them.

Add the trait to your model to navigate from your side:

```php
use CodingTriplets\Zatca\Laravel\Concerns\HasZatcaInvoices;

class Order extends Model
{
    use HasZatcaInvoices;
}

$order->zatcaInvoice;            // latest ZATCA invoice (with status, QR, totals …)
$order->zatcaInvoices;           // all of them, including notes
Invoice::query()->forSource($order)->get();
$invoice->source;                // back to your Order
```

Every invoice event carries `sourceType` and `sourceId`, so a listener can update your record without a
lookup:

```php
Event::listen(function (InvoiceAccepted $e) {
    if ($e->sourceType === Order::class) {
        Order::whereKey($e->sourceId)->update(['zatca_status' => $e->status]);
    }
});
```

## Line items

The package stores the calculated lines of every signed document in `zatca_invoice_lines` (quantity,
prices, discounts, net, VAT, total per line) and the VAT breakdown in `zatca_invoice_vat_breakdown`. They
are exactly the amounts in the signed XML.

```php
$invoice->lines;          // lines of the current document
$invoice->vatBreakdown;   // per VAT category / rate
```

A correction stores a new set of lines; the old ones stay with the old document. If you don't need them,
set `ZATCA_STORE_LINES=false`. The original JSON (`invoice_versions`) and the signed XML are always kept
regardless.
