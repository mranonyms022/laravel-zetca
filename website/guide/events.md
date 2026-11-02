# Events and notifications

The package fires Laravel events for everything you may want to react to: notifying staff, sending the
cleared invoice to the buyer, updating your order status.

- All events are in the namespace `CodingTriplets\Zatca\Core\Events`.
- They fire **after the database transaction commits**, so the data they refer to is always saved.
- They never contain secrets (no keys, no OTPs, no credentials).

## Invoice events

| Event | When |
|---|---|
| `InvoiceIssued` | A document was signed (counter and UUID assigned) |
| `InvoiceAccepted` | ZATCA reported/cleared it (`withWarnings` tells whether there were warnings) |
| `InvoiceRejected` | ZATCA rejected it |
| `InvoiceSubmissionFailed` | No final answer and no more automatic retries (`reason`) |
| `InvoiceReissued` | A corrected or reissued document was created |
| `CustomerCopyReissued` | B2C: the customer needs the new receipt (`qr`) |
| `InvoiceReportingDeadlineAtRisk` | B2C invoice still unreported `hoursLeft` hours before the deadline |
| `InvoiceReportingDeadlineBreached` | B2C 24-hour deadline passed |
| `ClearanceDeactivated` | ZATCA answered 303; the document is reported instead |

## Device events

`DeviceKeyGenerated`, `ComplianceCsidIssued`, `DeviceOnboarded`, `DeviceOnboardingFailed`,
`CertificateExpiring`, `CertificateExpired`, `CertificateRenewed`, `CertificateRenewalFailed`.

All properties are listed in the [events reference](/reference/events).

## Examples

Update your order when an invoice is final:

```php
use CodingTriplets\Zatca\Core\Events\InvoiceAccepted;
use CodingTriplets\Zatca\Core\Events\InvoiceRejected;
use Illuminate\Support\Facades\Event;

Event::listen(InvoiceAccepted::class, function (InvoiceAccepted $e) {
    Order::where('zatca_invoice_id', $e->publicId)->update(['zatca_status' => $e->status]);
});

Event::listen(InvoiceRejected::class, function (InvoiceRejected $e) {
    Notification::route('mail', 'accounting@example.com')->notify(new ZatcaRejected($e->publicId));
});
```

Warn before the B2C deadline:

```php
Event::listen(InvoiceReportingDeadlineAtRisk::class, function ($e) {
    Log::warning("ZATCA: invoice {$e->publicId} unreported, {$e->hoursLeft}h left");
});
```

Certificate expiry:

```php
Event::listen(CertificateExpiring::class, function ($e) {
    Notification::route('mail', 'it@example.com')->notify(new RenewZatcaCertificate($e->deviceId, $e->daysLeft));
});
```

::: tip Queue your listeners
Listeners run inside the worker or request that fired the event. Make slow listeners (mail, HTTP calls)
implement `ShouldQueue` so they do not slow down issuance.
:::
