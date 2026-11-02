# Testing the package (sandbox test plan)

A step-by-step plan to try every feature against ZATCA's **developer portal (sandbox)**. Nothing in the
sandbox is legally reported. Work through it in order: later steps use what earlier steps created.

Each step lists what to run and what you should see. Tick it off when it matches; if it doesn't, the
[Troubleshooting](./troubleshooting) page covers the usual causes.

::: tip Two terminals
Keep a worker running in a second terminal for the whole session:

```bash
php artisan queue:work --queue=zatca-b2c-reporting,zatca-b2b-clearance,zatca-b2b-generation,zatca-b2c-retry,zatca-b2b-retry
```

PHP snippets below are meant for `php artisan tinker` (or a test route / controller in your app).
Start each tinker session with `use CodingTriplets\Zatca\Laravel\Facades\Zatca;`.
:::

## 0. Preparation

| # | Do | Expect |
|---|---|---|
| 0.1 | Install and configure as in [Installation](./installation). `.env`: `ZATCA_ENVIRONMENT=sandbox`, `ZATCA_ENCRYPTION_KEY=…`, a queue connection other than `sync` | — |
| 0.2 | `php artisan migrate` | All `zatca_*` tables created, no errors |
| 0.3 | Windows/WAMP only: `ZATCA_CA_BUNDLE=C:/path/to/cacert.pem` | No `cURL error 60` later |
| 0.4 | `php artisan list zatca` | 14 commands, including `zatca:devices:import` |

## 1. Company, stores and devices from code

```php
$company = Zatca::companies()->create([
    'external_ref' => 'C-100',
    'name' => 'Maximum Speed',
    'legal_name' => 'Maximum Speed Tech Supply LTD',
    'vat_number' => '399999999900003',
    'crn' => '1010010000',
    'address' => ['street' => 'Prince Sultan', 'building_number' => '2322', 'district' => 'Al-Murabba',
                  'city' => 'Riyadh', 'postal_code' => '23333'],
    'stores' => [
        ['code' => 'RYD', 'name' => 'Riyadh Branch', 'external_ref' => 'S-1'],
        ['code' => 'DMM', 'name' => 'Dammam Branch', 'external_ref' => 'S-2'],
    ],
]);
```

| # | Do | Expect |
|---|---|---|
| 1.1 | Run the snippet | A `Company` with `external_ref = C-100`; 2 rows in `zatca_branches` |
| 1.2 | Run it again | Same company ID; still 1 company and 2 stores (idempotent) |
| 1.3 | `Zatca::companies()->createStore('C-100', ['code' => 'JED', 'name' => 'Jeddah', 'external_ref' => 'S-3'])` | A third store |

## 2. Device import (CSV / Excel)

Download the sample file [devices.csv](/samples/devices.csv) and put it in `storage/app/devices.csv`. It
references the company and stores above by your own IDs (`C-100`, `S-1`, `S-2`), and has no OTP column.

| # | Do | Expect |
|---|---|---|
| 2.1 | `php artisan zatca:devices:import storage/app/devices.csv --validate` | All rows `valid` except row 4 (`failed`: unknown store `S-9`). Nothing saved |
| 2.2 | `php artisan zatca:devices:import storage/app/devices.csv` | 3 × `created`, 1 × `failed`; exit code 1 because of the failed row |
| 2.3 | Run 2.2 again | 3 × `existing` (and the same failed row) — no duplicates |
| 2.4 | `php artisan zatca:devices` | `POS-01`, `POS-02` (B2C only, `0100`) and `ERP-01` (B2B only, `1000`) with status `REGISTERED` |
| 2.5 | Optional, Excel: `composer require openspout/openspout`, open the CSV in Excel, save as `.xlsx`, import it | Same result as 2.3 |
| 2.6 | From code: `Zatca::devices()->import(storage_path('app/devices.csv'))->toArray()` | Same report as an array |

## 3. Onboarding

The sandbox OTP is always `123345`.

| # | Do | Expect |
|---|---|---|
| 3.1 | `Zatca::devices()->onboardMany([['device' => 'POS-01', 'otp' => '123345'], ['device' => 'ERP-01', 'otp' => '123345']])->counts()` | `['onboarded' => 2]` (a few seconds per device) |
| 3.2 | `php artisan zatca:device:onboard <id of POS-02>` and enter `123345` | Device `ACTIVE` |
| 3.3 | `Zatca::devices()->onboard('POS-01', '000000')` | Refused: the device is already active (use renewal instead) |
| 3.4 | `php artisan zatca:devices` | All three `ACTIVE` with a certificate expiry date |
| 3.5 | Search `zatca_device_onboardings` and your `storage/logs` for `123345` | Not found — OTPs are never stored |

Note the device IDs: below, `$pos` is the ID of `POS-01` (B2C) and `$erp` the ID of `ERP-01` (B2B).

## 4. B2C invoice

```php
$r = Zatca::b2c()->issue([
    'invoice_number' => 'T-B2C-001',
    'lines' => [['name' => 'Coffee', 'quantity' => '2', 'unit_price' => '12.50', 'vat_category' => 'S', 'vat_rate' => '15']],
], ['device' => $pos])->toArray();
```

| # | Do | Expect |
|---|---|---|
| 4.1 | Run the snippet | `status: true`, `counter: 1`, a `qr`, `processing_status: QUEUED` |
| 4.2 | Wait for the worker, then `php artisan zatca:invoice show <invoice_id>` | `processing: REPORTED` (sandbox warning `certificate-issuer-name` is normal) |
| 4.3 | Run the snippet again (same number) | Same `invoice_id` and `counter` — idempotent, nothing new issued |
| 4.4 | Same number, different price | `DuplicateInvoice` exception |
| 4.5 | Issue `T-B2C-002` | `counter: 2` (next ICV on the device) |
| 4.6 | `Zatca::b2c()->validate([...invalid payload...])` e.g. `vat_rate => '0'` without category | Errors with field paths and rule IDs; nothing stored |

## 5. Discounts

Issue each as a new B2C invoice (new number each time) and wait for `REPORTED`.

| # | Payload part | Expect |
|---|---|---|
| 5.1 | Line: `'discount' => '1.00'` (per unit) on 2 × 12.50 | Line net 23.00 |
| 5.2 | Line: `'discounts' => [['amount' => '5.00', 'reason' => 'Promotion']]` on 3 × 100 | Line net 295.00, VAT 44.25 |
| 5.3 | Line: `'discounts' => [['percent' => '10', 'reason' => 'Member']]` on 3 × 100 | Line net 270.00 |
| 5.4 | Header: `'allowances' => [['percent' => '5', 'reason' => 'Loyalty', 'vat_category' => 'S', 'vat_rate' => '15']]` | Allowance 5 % of the S-group net |
| 5.5 | Two lines, one `S` 15 % and one `Z` (`exemption_reason_code => 'VATEX-SA-35'`), header `['amount' => '33.33', 'reason' => 'Voucher', 'distribute' => true]` | Two allowances in the XML, totalling 33.33 |
| 5.6 | Line discount larger than the line | Validation error `BR-27`, nothing issued |

See [Discounts](./payload#discounts) for the rules.

## 6. Stored lines and the link to your own record

| # | Do | Expect |
|---|---|---|
| 6.1 | `Invoice::where('public_id', $id)->first()->lines` (model `CodingTriplets\Zatca\Laravel\Database\Models\Invoice`) for 5.3 | One line: `line_discount 30.00`, `net_amount 270.00`, `vat_amount 40.50` |
| 6.2 | `->vatBreakdown` | One row per VAT category/rate |
| 6.3 | Add `use HasZatcaInvoices;` to your own order model; issue with `['device' => $pos, 'source' => $order]` | `$order->zatcaInvoice` returns the invoice |
| 6.4 | Listen to `InvoiceAccepted` and log `$e->sourceType`, `$e->sourceId` | Your model class and ID |

## 7. B2B invoice

```php
$r = Zatca::b2b()->issue([
    'invoice_number' => 'T-B2B-001',
    'supply_date' => now()->toDateString(),
    'buyer' => ['name' => 'Fatoora Samples LTD', 'vat_number' => '399999999800003',
                'address' => ['street' => 'Salah Al-Din', 'building_number' => '1111', 'district' => 'Al-Murooj',
                              'city' => 'Riyadh', 'postal_code' => '12222', 'country' => 'SA']],
    'lines' => [['name' => 'Consulting', 'quantity' => '10', 'unit_code' => 'HUR', 'unit_price' => '500', 'vat_category' => 'S', 'vat_rate' => '15']],
], ['device' => $erp])->toArray();
```

| # | Do | Expect |
|---|---|---|
| 7.1 | Run the snippet, wait for the worker | `processing: CLEARED` |
| 7.2 | Get the cleared XML as in [B2B › Getting the cleared invoice](./b2b#getting-the-cleared-invoice) | XML signed by ZATCA |
| 7.3 | Same payload without `buyer.vat_number` and without other buyer ID | Validation error (buyer identification) |
| 7.4 | `Zatca::b2c()->issue(<any payload>, ['device' => $erp])` | `DeviceUnavailable`: `ERP-01` is registered for B2B only |

## 8. Credit and debit notes, cancellation

| # | Do | Expect |
|---|---|---|
| 8.1 | `Zatca::invoices()->creditNote(<id of T-B2C-001>, ['invoice_number' => 'T-CN-1', 'lines' => [['name' => 'Coffee', 'quantity' => '1', 'unit_price' => '12.50', 'vat_category' => 'S', 'vat_rate' => '15']]], 'Returned')` | Credit note reported; original `credited_amount 14.38` |
| 8.2 | A second credit note for 2 × 12.50 on the same invoice | `OperationNotAllowed`: exceeds the amount that can still be credited |
| 8.3 | `Zatca::invoices()->debitNote(...)` for 5.00 + VAT | Debit note reported; `creditableAmount()` goes up by 5.75 |
| 8.4 | `php artisan zatca:invoice cancel <id of T-B2C-002> --reason="Order cancelled"` | Prints the credit note `T-B2C-002-CN`; original `cancellation_invoice_id` set |
| 8.5 | Cancel it again | Refused: already cancelled |
| 8.6 | Stop the worker; `Zatca::b2b()->submit(<B2B payload with a new number>, ['device' => $erp])`; then `php artisan zatca:invoice discard <id> --reason="Entered twice"`; start the worker | Status `CANCELLED`; no counter used on `ERP-01` |
| 8.7 | Cancel a B2B invoice (7.1) | A B2B credit note, `CLEARED` |

## 9. Rejections, retry and correction

The sandbox rejects real rule violations only, and the package already blocks most of them locally, so a
rejection is not easy to provoke on purpose ⚠. If you get one (or after a network failure), test:

| # | Do | Expect |
|---|---|---|
| 9.1 | `php artisan zatca:invoice show <id>` | `REJECTED`, `last error` with ZATCA's code |
| 9.2 | `php artisan zatca:invoice correct <id> --file=fixed.json --reason="…"` | New issuance (new UUID, next counter); after the worker: `REPORTED`/`CLEARED` |
| 9.3 | `php artisan zatca:invoice abandon <id> --reason="…"` instead of 9.2 | `ABANDONED` ⚠ (see [Cancelling invoices](./cancellation#abandon-a-rejected-invoice)) |
| 9.4 | Disconnect the network, issue a B2C invoice, reconnect, wait | `RETRY_PENDING` then `REPORTED` on its own |
| 9.5 | `php artisan zatca:invoice retry <id> --now` on a `FAILED` invoice | Submitted again with the identical document |

All these paths are also covered by the package's automated tests (`composer test`).

## 10. HTTP API (optional)

| # | Do | Expect |
|---|---|---|
| 10.1 | `.env`: `ZATCA_API_ENABLED=true`; add your auth middleware to `zatca.api.middleware` | Routes under `/api/zatca` |
| 10.2 | `POST /api/zatca/b2c/invoices` with the 4.1 payload (new number) and header `X-Zatca-Device: <id>`, see [HTTP API](./http-api) | `201` and the same JSON as `toArray()` |
| 10.3 | `GET /api/zatca/invoices/{invoice_id}` | Current statuses |

## 11. Integrity and maintenance commands

| # | Do | Expect |
|---|---|---|
| 11.1 | `php artisan zatca:chain:verify` | Every device chain intact (ICV gap-free, PIH links valid) |
| 11.2 | `php artisan zatca:audit:verify` | Audit hash chain intact |
| 11.3 | `php artisan zatca:deadlines:check` | No breaches (all B2C reported) |
| 11.4 | `php artisan zatca:reconcile` | Nothing to re-queue |
| 11.5 | `php artisan zatca:devices:check-expiry` | No expiring certificates (sandbox certificates are new) |
| 11.6 | `php artisan schedule:list` | `zatca:outbox:relay`, `outbox:prune`, `chain:verify`, `deadlines:check`, `reconcile`, `devices:check-expiry` |

## 12. Dashboard

Log in to your app as a user with the dashboard permissions (in `APP_ENV=local` every logged-in user has
them, see [Dashboard](./dashboard#who-may-do-what)).

| # | Do | Expect |
|---|---|---|
| 12.1 | Open `/admin/zatca` | Overview with the B2B and B2C panels and the numbers of the invoices you created |
| 12.2 | B2C list → tab *Reported*, search `T-B2C` | Only matching invoices |
| 12.3 | Open an invoice, go through every tab | Lines with discounts (step 5), JSON, issuance with UUID/ICV/hash, submissions, history |
| 12.4 | *Issuances* → Download signed XML | An `.xml` file |
| 12.5 | On a reported invoice: *Cancel (credit note)* with a reason | Green message; *Summary* links to the credit note |
| 12.6 | `php artisan zatca:audit:verify` | Intact; the cancel appears in `zatca_audit_logs` with your user ID |
| 12.7 | Log in as a user without permissions (with `APP_ENV=production` and a `Zatca::auth()` callback that returns `false`) | `403` |
| 12.8 | View the page source | No keys, secrets or OTPs |

## 13. Security checks

| # | Check | Expect |
|---|---|---|
| 13.1 | `zatca_device_keys.encrypted_private_key` | Encrypted text, not a PEM key |
| 13.2 | `zatca_certificates.encrypted_secret` | Encrypted |
| 13.3 | `grep -ri "PRIVATE KEY\|123345" storage/logs` | Nothing |
| 13.4 | Signed XML files on the storage disk | Not under `public/`; no public URL |
| 13.5 | Invoice events (dump one in a listener) | No keys, secrets or OTPs |

## Run the package's own tests

The repository has 160+ automated tests (unit, integration, a fake ZATCA server, golden files compared
with the ZATCA SDK):

```bash
git clone https://github.com/mranonyms022/laravel-zetca.git && cd laravel-zetca
composer install
composer test                                   # SQLite
ZATCA_TEST_DB=mysql DB_PORT=3306 composer test  # MySQL/MariaDB (database zatca_test)
```
