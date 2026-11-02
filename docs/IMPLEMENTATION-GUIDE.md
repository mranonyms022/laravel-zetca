# Implementation guide

A step-by-step plan for integrating `codingtriplets/laravel-zatca` into a real Laravel application,
from an empty project to production. Each step says what to build, with code you can copy.

Reference material: user docs (`website/`, published site), [TECHNICAL.md](TECHNICAL.md) (internals),
[ARCHITECTURE.md](ARCHITECTURE.md) (design decisions).

---

## Phase 0 — Decide before you code (½ day)

| Decision | Options | Recommendation |
|---|---|---|
| Which documents? | B2C only, B2B only, both | Most retail/POS: B2C. ERP/wholesale: B2B. Mixed: both |
| Devices | One per POS terminal / per ERP / a pool | One per terminal; pool of N for web shop/API |
| B2C reporting mode | `queue` / `sync` | `queue` |
| Integration style | PHP calls in the same app / HTTP API from other systems | Same app if it is Laravel; API for non-PHP POS |
| Queue + cache | Redis / database | Redis |
| Artifact storage | Local private disk / S3 | S3 (private bucket) as soon as you have >1 server |
| Correction policy (⚠) | `zatca.correction.*` | Keep defaults; confirm with your tax advisor |

Write the decisions down; they become your `config/zatca.php` and `.env`.

---

## Phase 1 — Install and configure (1 hour)

```bash
composer require codingtriplets/laravel-zatca
php artisan vendor:publish --tag=zatca-config
php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"   # → ZATCA_ENCRYPTION_KEY
php artisan migrate
```

`.env` (development):

```dotenv
ZATCA_ENVIRONMENT=sandbox
ZATCA_ENCRYPTION_KEY=base64:...
ZATCA_ARTIFACT_DISK=zatca
ZATCA_B2C_REPORTING_MODE=queue
QUEUE_CONNECTION=redis
CACHE_STORE=redis              # CACHE_DRIVER on Laravel 9/10
```

Private disk in `config/filesystems.php`:

```php
'zatca' => [
    'driver' => 'local',
    'root' => storage_path('app/zatca'),   // NOT under public/
    'visibility' => 'private',
    'throw' => true,
],
// production with several servers:
// 'zatca' => ['driver' => 's3', 'bucket' => env('ZATCA_BUCKET'), 'visibility' => 'private', ...],
```

Windows/WAMP only: `ZATCA_CA_BUNDLE=C:/path/to/cacert.pem`.

---

## Phase 2 — Company and devices in the sandbox (1 hour)

```bash
php artisan zatca:taxpayer:register --name="My Company" --legal-name="My Company LLC" \
    --vat=399999999900003 --crn=1010010000 --branch-code=HQ --branch-name="Head Office" \
    --street="King Fahd Road" --building=1234 --district=Olaya --city=Riyadh --postal-code=12345

php artisan zatca:device:register --vat-registration=1 --branch=1 --name=POS-01 --types=1100 \
    --industry="Retail" --location="Riyadh"

php artisan zatca:device:onboard 1      # sandbox OTP: 123345
php artisan zatca:devices
```

Or from your code — for example when an admin uploads an Excel file of terminals:

```php
Zatca::companies()->create(['external_ref' => $company->id, 'name' => …, 'legal_name' => …,
    'vat_number' => …, 'address' => […], 'stores' => [['code' => 'HQ', 'name' => 'Head Office', 'external_ref' => $store->id]]]);

$report = Zatca::devices()->import($request->file('devices')->getRealPath());   // CSV, or XLSX with openspout
// later, with the OTPs from the Fatoora portal:
Zatca::devices()->onboardMany([['device' => 'POS-01', 'otp' => $otp]]);
```

See the user guide page "Devices from code and Excel" for the file columns and the report.

Store the device ID where your app can find it, for example on your `terminals` table:

```php
Schema::table('terminals', fn (Blueprint $t) => $t->unsignedBigInteger('zatca_device_id')->nullable());
```

---

## Phase 3 — Map your data to the payload (½–2 days)

Write one class that turns your order into the package payload. Keep all tax decisions here.

```php
namespace App\Zatca;

use App\Models\Order;

final class OrderToZatcaPayload
{
    public function b2c(Order $order): array
    {
        return [
            'invoice_number' => $order->number,                       // unique per company
            'issued_at' => $order->paid_at->timezone('Asia/Riyadh')->format('Y-m-d\TH:i:s'),
            'payment_means_code' => $order->paid_by_card ? '48' : '10',
            'lines' => $order->items->map(fn ($item) => array_filter([
                'name' => $item->name,
                'quantity' => (string) $item->quantity,
                'unit_price' => number_format($item->net_unit_price, 2, '.', ''),  // excl. VAT
                'discount' => $item->discount > 0 ? number_format($item->discount, 2, '.', '') : null,
                'vat_category' => $item->vat_category,                // S / Z / E / O
                'vat_rate' => (string) $item->vat_rate,               // 15 or 0
                'exemption_reason_code' => $item->vatex_code,         // for Z/E/O
                'exemption_reason' => $item->vatex_reason,
            ], fn ($v) => $v !== null))->values()->all(),
        ];
    }

    public function b2b(Order $order): array
    {
        $c = $order->customer;

        return $this->b2c($order) + [
            'supply_date' => $order->delivered_at?->format('Y-m-d') ?? $order->paid_at->format('Y-m-d'),
            'buyer' => [
                'name' => $c->legal_name,
                'vat_number' => $c->vat_number,                       // or 'identification' => ['scheme' => 'CRN', 'value' => ...]
                'address' => [
                    'street' => $c->street, 'building_number' => $c->building_number,
                    'district' => $c->district, 'city' => $c->city,
                    'postal_code' => $c->postal_code, 'country' => 'SA',
                ],
            ],
        ];
    }
}
```

Checklist for this step:

- [ ] Prices are **net** (excluding VAT). If your system stores gross prices, convert carefully and
      compare the package's `total_incl_vat` with your own total in a test.
- [ ] Every product has a VAT category; zero-rated/exempt products have a `VATEX-…` code.
- [ ] Invoice numbers are unique per company and never reused.
- [ ] Run `Zatca::b2c()->validate($payload)` over a sample of real orders and fix all errors.

---

## Phase 4 — Issue invoices (½ day)

### B2C at checkout

```php
use CodingTriplets\Zatca\Laravel\Facades\Zatca;
use CodingTriplets\Zatca\Laravel\Invoices\DeviceUnavailable;
use CodingTriplets\Zatca\Laravel\Invoices\DuplicateInvoice;

public function checkout(Order $order, OrderToZatcaPayload $map)
{
    // ... payment succeeded ...
    try {
        $result = Zatca::b2c()->issue($map->b2c($order), [
            'device' => $order->terminal->zatca_device_id,
            'actor_id' => auth()->id(),
        ]);
    } catch (DeviceUnavailable $e) {
        report($e);                     // alert: device not onboarded / expired
        return back()->withErrors('E-invoicing is unavailable on this terminal.');
    } catch (DuplicateInvoice $e) {
        report($e);                     // same number, different content: a bug in numbering
        throw $e;
    }

    if (! $result->status) {            // validation failed: nothing was signed
        report(new \RuntimeException('ZATCA validation: ' . json_encode($result->toArray()['errors'] ?? [])));
        return back()->withErrors('Invoice data is invalid.');
    }

    $order->update([
        'zatca_invoice_id' => $result->toArray()['invoice_id'],
        'zatca_qr' => $result->toArray()['qr'],
        'zatca_counter' => $result->toArray()['counter'],
    ]);

    return view('receipt', ['order' => $order]);
}
```

Add the columns: `zatca_invoice_id` (string 26), `zatca_qr` (text), `zatca_counter` (bigint),
`zatca_status` (string 40).

### Receipt QR

Any QR library works; the `qr` value is the exact string to encode.

```bash
composer require simplesoftwareio/simple-qrcode
```

```blade
{!! QrCode::size(160)->generate($order->zatca_qr) !!}
```

### B2B

```php
$result = Zatca::b2b()->issue($map->b2b($order), ['device' => $erpDeviceId]);
// later, InvoiceAccepted → send the cleared XML to the buyer (Phase 5)
```

Use `submit()` instead of `issue()` for large batches (signing then runs on the queue).

### Idempotency

Call `issue()` again with the same payload whenever you are unsure whether the first call succeeded
(timeout, crash): you get the original result. Never generate a new invoice number for a retry.

---

## Phase 5 — React to outcomes (½ day)

`app/Providers/EventServiceProvider.php` (or `AppServiceProvider::boot()` on Laravel 11+):

```php
use CodingTriplets\Zatca\Core\Events as Z;

Event::listen(Z\InvoiceAccepted::class, [ZatcaListener::class, 'accepted']);
Event::listen(Z\InvoiceRejected::class, [ZatcaListener::class, 'rejected']);
Event::listen(Z\InvoiceSubmissionFailed::class, [ZatcaListener::class, 'failed']);
Event::listen(Z\CustomerCopyReissued::class, [ZatcaListener::class, 'reissued']);
Event::listen(Z\InvoiceReportingDeadlineAtRisk::class, [ZatcaListener::class, 'deadline']);
Event::listen(Z\InvoiceReportingDeadlineBreached::class, [ZatcaListener::class, 'deadline']);
Event::listen(Z\CertificateExpiring::class, [ZatcaListener::class, 'certificate']);
```

```php
final class ZatcaListener implements ShouldQueue
{
    public function accepted(Z\InvoiceAccepted $e): void
    {
        Order::where('zatca_invoice_id', $e->publicId)->update(['zatca_status' => $e->status]);
        if ($e->workflow === 'b2b') {
            SendClearedInvoice::dispatch($e->publicId);      // reads the cleared_xml artifact
        }
    }

    public function rejected(Z\InvoiceRejected $e): void
    {
        Order::where('zatca_invoice_id', $e->publicId)->update(['zatca_status' => 'REJECTED']);
        Notification::route('mail', config('app.accounting_email'))->notify(new ZatcaNeedsAttention($e->publicId, 'rejected'));
    }

    public function failed(Z\InvoiceSubmissionFailed $e): void { /* same: notify */ }

    public function reissued(Z\CustomerCopyReissued $e): void
    {
        Order::where('zatca_invoice_id', $e->publicId)->update(['zatca_qr' => $e->qr]);  // reprint if needed
    }

    public function deadline($e): void { /* page someone */ }

    public function certificate(Z\CertificateExpiring $e): void { /* ticket to renew device $e->deviceId */ }
}
```

`SendClearedInvoice` reads the artifact as shown in the user docs (B2B → Getting the cleared invoice).

---

## Phase 6 — Handle problems (1 day)

The dashboard (`/admin/zatca`) covers this; if you prefer your own admin screen, or for scripts, use the PHP API or the commands:

| Situation | Action in your admin | Package call |
|---|---|---|
| `FAILED`, `OUTCOME_UNKNOWN`, `MANUAL_REVIEW` (auth) | "Resend" button | `Zatca::invoices()->retry($id, auth()->id())` |
| `REJECTED` / `VALIDATION_FAILED` | Edit form with the stored JSON, "Save & resend" | `Zatca::invoices()->correct($id, $payload, $reason, auth()->id())` |
| Rejected for a non-data reason | "Reissue" | `Zatca::invoices()->reissueUnchanged($id, $reason, auth()->id())` |
| Accepted but wrong / returned goods | "Credit note" / "Debit note" | `Zatca::invoices()->creditNote($id, $payload, $reason, auth()->id())` |
| Four-eyes on | "Approve" (other user) | `Zatca::invoices()->approve($id, auth()->id())` |

Load the data for the screen:

```php
use CodingTriplets\Zatca\Laravel\Database\Models\{Invoice, InvoiceVersion, ZatcaError};

$invoice = Invoice::where('public_id', $id)->firstOrFail();
$json = InvoiceVersion::find($invoice->current_version_id)->payload;
$errors = ZatcaError::where('invoice_id', $invoice->id)->whereNull('resolved_at')->get(['code', 'message', 'severity', 'source']);
```

Catch `OperationNotAllowed` and show its message; it explains why an action is not possible.

---

## Phase 7 — Workers, scheduler, deployment (½ day)

Cron:

```text
* * * * * cd /var/www/app && php artisan schedule:run >> /dev/null 2>&1
```

Supervisor (`/etc/supervisor/conf.d/zatca.conf`):

```ini
[program:zatca-worker]
command=php /var/www/app/artisan queue:work redis --queue=zatca-b2c-reporting,zatca-b2b-clearance,zatca-b2b-generation,zatca-b2c-retry,zatca-b2b-retry --sleep=1 --tries=1 --max-time=3600
numprocs=4
autostart=true
autorestart=true
stopwaitsecs=120

[program:zatca-relay]
command=php /var/www/app/artisan zatca:outbox:relay --daemon --max-time=3600
numprocs=1
autostart=true
autorestart=true
```

Deploy script additions:

```bash
php artisan migrate --force
php artisan config:cache
php artisan queue:restart
```

---

## Phase 8 — Test your integration (1–2 days)

In the sandbox, run each scenario and tick it off:

- [ ] B2C invoice, all your VAT categories → `REPORTED`
- [ ] B2C with a discount and a document allowance → totals match your system
- [ ] B2B invoice → `CLEARED`, cleared XML delivered
- [ ] Same request twice → one invoice, same counter
- [ ] Credit note on a reported invoice → `REPORTED`
- [ ] Invalid data (e.g. 3-digit building number for a B2B buyer) → `status: false`, no counter used
- [ ] Stop the workers, issue 20 invoices, start the workers → all reported
- [ ] Block outbound network for a few minutes → `RETRY_PENDING`, then reported
- [ ] Correct a rejected invoice → new counter, `REPORTED`, `CustomerCopyReissued` received
- [ ] `php artisan zatca:chain:verify` → OK

Automated tests in your app: use `Event::fake()` and `Http::fake()` for the ZATCA host, or keep a
separate sandbox device for end-to-end runs. Never point tests at production.

---

## Phase 9 — Simulation, then production

1. `ZATCA_ENVIRONMENT=simulation`; register devices with `--environment=simulation`; onboard with OTPs
   from the Fatoora portal's simulation section; run Phase 8 again with real company data.
2. `ZATCA_ENVIRONMENT=production`; new production devices and OTPs.
3. Work through the **Going live** checklist in the user docs.
4. First production day: watch `REJECTED` / `FAILED` counts hourly.

---

## Phase 10 — Scaling plan

| Volume (invoices/s, sustained) | Setup |
|---|---|
| < 10 | One server, database queue acceptable, 1–2 devices |
| 10 – 200 | Redis, 2+ app servers, S3 artifacts, one device per terminal / pool of 10–20 |
| 200 – 5,000 | Octane, dedicated DB primary on NVMe, 4–16 workers per server, device pool sized from your benchmark |
| > 5,000 | Requires a benchmarked architecture (many devices, database sharding by company/device); see ARCHITECTURE §15.6 |

Benchmark before you promise numbers: issue invoices from a load tool against a staging copy (sandbox
devices), watch p95 latency, DB CPU and outbox lag. Add devices first, then app servers, then database
capacity.

---

## Publishing the documentation site

The user docs live in `website/` (VitePress).

```bash
cd website
npm install
npm run dev        # local preview at http://localhost:5173
npm run build      # static site in website/.vitepress/dist
```

GitHub Pages (workflow `.github/workflows/docs.yml` is included):

1. Push the repository.
2. GitHub → **Settings → Pages → Source: GitHub Actions**.
3. The workflow builds on every push to `main` that changes `website/`, or run it manually
   (**Actions → Docs → Run workflow**).
4. The site appears at `https://<user>.github.io/<repo>/`.

Custom domain (e.g. `docs.example.com`): add `website/public/CNAME` containing the domain, set
`DOCS_BASE: /` in the workflow, and point a DNS CNAME record to `<user>.github.io`.

Other hosts (Netlify, Vercel, Cloudflare Pages): build command `npm run build`, base directory
`website`, output `website/.vitepress/dist`.

---

## What is still coming

| Milestone | Content |
|---|---|
| M6.1 | Done: discounts, stored lines, source link, cancellation, device import |
| M7 | Web dashboard: done (M7a lists, detail, actions, roles; M7b devices, companies, errors, queue, audit, settings; M7c correction editor, note forms, reports, charts, exports) |
| M8 | Release: benchmarks, PostgreSQL/MySQL/SQL Server CI runs, simulation verification, 1.0 |

Until 1.0, pin a minor version (`^0.6`) and read the changelog before upgrading.
