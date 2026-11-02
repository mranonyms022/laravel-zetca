# Dashboard

A web dashboard for your operators: invoices and their actions, devices (register, import from Excel/CSV,
onboard with an OTP, renew), companies and stores, errors, the queue backlog, the audit history and the
active settings, a correction editor, credit/debit note forms, reports with charts and CSV exports. It is plain Blade with one prebuilt CSS file — no JavaScript framework
and no build step in your application (only small inline confirmations before disable / delete).


## Open it

The dashboard is on by default at **`/admin/zatca`** for logged-in users who have the permissions below.

```dotenv
ZATCA_UI_ENABLED=true          # false removes all dashboard routes
ZATCA_UI_PREFIX=admin/zatca
ZATCA_UI_DOMAIN=               # optional, e.g. ops.example.com
ZATCA_UI_GUARD=                # optional auth guard, e.g. admin
```

```php
// config/zatca.php
'ui' => [
    'middleware' => ['web', 'auth'],   // add your own, e.g. 'verified', '2fa'
    'theme' => 'light',                // light | dark | auto
],
```

## Who may do what

Every page and every action checks a permission. Nobody gets access until you grant it (except in the
`local` environment, see below).

| Permission | Allows |
|---|---|
| `zatca.dashboard.view` | Open the dashboard and the lists |
| `zatca.b2b.view` / `zatca.b2c.view` | See B2B / B2C invoices and their details |
| `zatca.b2b.retry` / `zatca.b2c.retry` | Retry a submission |
| `zatca.invoices.reprocess` | Reissue unchanged, approve corrections |
| `zatca.invoices.correct` | Cancel (full credit note), abandon a rejected invoice |
| `zatca.invoices.edit` | Discard a never-signed invoice |
| `zatca.invoices.export` | Download signed / cleared XML |
| `zatca.devices.view` | See devices, their certificates (public data only) and onboarding history |
| `zatca.devices.onboard` | Register, edit, disable / enable and delete devices, import them from a file, onboard / resume with an OTP |
| `zatca.devices.renew` | Renew a device's production certificate with a new OTP |
| `zatca.companies.view` / `zatca.companies.manage` | See / add, edit, disable / enable and delete companies and stores |
| `zatca.errors.view` / `zatca.errors.resolve` | See errors, groups and warnings / mark errors resolved |
| `zatca.queue.view` | Queue monitor |
| `zatca.audit.view` | Audit history and the tamper-chain check |
| `zatca.settings.manage` | Settings page (read-only view of the configuration) |

| `zatca.reports.view` | Reports page and the VAT summary CSV |

`zatca.invoices.correct` also opens the correction editor and the credit/debit note forms;
`zatca.invoices.export` also allows the CSV exports of documents. `zatca.logs.view` and
`zatca.credentials.manage` are not used by any page yet.

The package decides in this order:

1. **Your callback**, if you register one — simplest for a start:

   ```php
   // AppServiceProvider::boot()
   use CodingTriplets\Zatca\Laravel\Facades\Zatca;

   Zatca::auth(function ($user, string $ability, $invoice = null): bool {
       return $user->is_admin                                       // everything
           || ($user->role === 'cashier-lead' && in_array($ability, [
                  'zatca.dashboard.view', 'zatca.b2c.view', 'zatca.b2c.retry',
              ], true));
   });
   ```

   `$invoice` is the invoice model for invoice pages and actions (useful to limit users to their own
   company or branch).

2. **Laravel Gates / Spatie Laravel Permission** — without a callback the package asks `$user->can($ability)`.
   With Spatie, create permissions named exactly like the table above and assign them to roles.

3. **Otherwise denied.** In `APP_ENV=local` any logged-in user is allowed, so you can try the dashboard
   right away on your machine. Never run production with `APP_ENV=local`.

You can also replace the whole logic with your own class implementing
`CodingTriplets\Zatca\Laravel\Dashboard\PermissionResolver` (`zatca.authorization.resolver`).

## Pages

**Overview** — unresolved errors, active devices, certificates expiring within 30 days, B2C invoices less
than 4 hours from their reporting deadline, and per workflow the number of documents by state. Choose the
period (today, 7 days, 30 days, all).

**B2B / B2C lists** — tabs: All, Pending, Cleared/Reported, Rejected, Failed, Manual review, Cancelled /
abandoned. Search by invoice number, external ID (prefix), invoice ID, UUID or buyer VAT number; filter by
device, document type and date. 50 per page, newest first.

**Invoice detail** — tabs:

| Tab | Shows |
|---|---|
| Summary | Statuses, dates, totals, credited/debited amounts, links to the original or the cancelling credit note, your linked record |
| Lines | The stored lines and VAT breakdown of the current document |
| JSON versions | The original JSON and every correction, with reason, user and changed fields |
| Issuances | Every signed document: UUID, counter (ICV), previous hash, hash; XML download |
| Submissions | Every call to ZATCA: endpoint, HTTP status, ZATCA status, outcome, error, duration |
| Errors & warnings | ZATCA and local errors, resolved or not |
| History | Every status change with time and user |
| Notes | Credit and debit notes of this invoice |

**Actions** appear only when they fit the invoice's state and you have the permission. Each asks for a
reason where one is required (recorded in the history and the audit log). The logged-in user's ID is
recorded as the actor (numeric user IDs; approvals need one).

| Action | When |
|---|---|
| Retry submission | Queued, retry pending, failed, outcome unknown, manual review |
| Reissue unchanged | Rejected |
| Approve correction | A correction waits for four-eyes approval |
| Cancel (credit note) | Accepted invoice, not yet credited |
| Discard | Never signed: validation failed, waiting, failed before signing |
| Abandon ⚠ | Rejected |

See [Cancelling invoices](./cancellation) and [Rejections and corrections](./corrections) for what each
does.

### Correction editor

**Correct JSON** appears on invoices that can be corrected: rejected by ZATCA, or never signed (validation
failed, failed before signing, manual review). It needs `zatca.invoices.correct`.

- Left: the current JSON (read-only). Right: the corrected JSON, prefilled with the current one. Open
  errors of the invoice are listed above, so you see what to fix.
- **Check only** runs the same validation as saving and lists errors and warnings. Nothing is saved.
- **Save correction and send** requires a reason. It stores a new JSON version (the original and earlier
  versions never change) and then:
  - for a rejected invoice, signs a **new document**: new UUID, next counter (ICV), current previous-hash;
    the rejected document stays in the chain;
  - for a never-signed invoice, signs and sends it as usual.
- With `correction.four_eyes` on, the correction waits for another user's **Approve correction**.
- Invalid JSON or an invalid invoice saves nothing.

Same rules as [`Zatca::invoices()->correct()`](./corrections#b-correct-and-reprocess).

### Credit and debit notes

**Credit note** and **Debit note** appear on accepted invoices (reported / cleared). Enter the note number
(prefilled, e.g. `INV-7-CN1`), the reason and the lines as JSON (the credit form is prefilled with the
invoice's lines; edit them to what is credited). The page shows how much can still be credited; a credit
note above that is refused before anything is signed. Buyer and supply date are copied from the invoice.
To credit the full invoice use **Cancel (credit note)** instead. See [Credit and debit notes](./notes).

### Reports

*Reports* (`zatca.reports.view`), by invoice date, up to 366 days, filtered by workflow, company and
device:

- **Documents per day** for B2B and B2C: cleared/reported, pending, rejected or failed (bar chart; hover a
  bar for the numbers, or *Show as table*). The overview page shows the last 14 days the same way.
- **VAT summary**: count, amount excluding VAT, VAT and amount including VAT of **accepted** documents
  per type, and the net (invoices + debit notes − credit notes). A working figure: reconcile it with your
  accounting before filing your VAT return.
- **Devices**: the 20 busiest devices with accepted and rejected/failed counts.
- **Errors by category** in the period.

### Exports (CSV)

| Export | Where | Permission |
|---|---|---|
| VAT summary | *Reports* → Download VAT summary | `zatca.reports.view` |
| All documents of the report period | *Reports* → Download all documents | `zatca.reports.view` + `zatca.invoices.export` |
| The current B2B or B2C list (tab, search, filters) | List → Export this list | workflow view + `zatca.invoices.export` |

Files are UTF-8 with a BOM (Excel shows Arabic correctly). Columns of the document export: invoice ID,
workflow, type, invoice number, external ID, date, device, buyer VAT number, currency, totals, statuses,
UUID, billing reference, first issued at. Text that would start a spreadsheet formula (`=`, `+`, `-`, `@`)
gets a leading apostrophe. No keys, XML or QR data are exported. Large exports are streamed in chunks of
1,000.

### Devices

**List** — search by name, UUID or serial; filter by status and environment. Certificates expiring within
30 days are highlighted. Each row has **Edit**, **Disable / Enable** and **Delete**:

- **Edit** — name, store, industry and location are written into the certificate, so they can only be
  changed while the device is *Registered* or *Onboarding failed*. Afterwards the page is read-only.
- **Disable** — the device becomes *Suspended* and refuses new invoices (no ICV is used). **Enable** puts it
  back to *Active* (or *Expired* when its certificate has expired, or *Registered* if it was never onboarded).
- **Delete** — removes a device that never issued a document and never received a production certificate.
  Any other device is **decommissioned** instead: it can never issue again, and its invoices and certificates
  are kept for the retention period.

**Register device** — pick the company and store, enter name, invoice types (`0100` B2C, `1000` B2B,
`1100` both), industry and location. Create the company and store first (*Companies & stores*).

**Import from file** — upload a CSV, XLSX or ODS file (max 5 MB) in the
[device file format](./device-import#_2-the-device-file). Tick *Check only* first: it validates every row and
changes nothing. Then upload again without it. Rows already registered are reported as *existing*, so
uploading the same file twice is safe. Tick *Onboard rows that have an OTP* to onboard straight away from
an `otp` column. The uploaded file is deleted right after reading; OTPs are never stored.

**Device page** — details, invoice counter (last ICV), certificates (serial, validity, fingerprint — never
the secret or the certificate body), onboarding attempts and renewals. Depending on the state:

| State | Action |
|---|---|
| Registered, onboarding failed | **Onboard**: enter the 6-digit OTP from the Fatoora portal (sandbox: `123345`) |
| Compliance checks did not pass | **Resume onboarding**: no new OTP needed; fix the cause first (often the seller address) |
| Active, renewal due/failed, expired | **Renew the production certificate** with a new OTP |

The OTP field is never refilled after an error and the OTP is not kept in the session, database or logs.

### Companies & stores

Two tables: **Companies** (VAT registration, CRN, city, number of stores and devices, status) and
**Stores** (code, company, your ID, devices, status; filter by company). Add a company (name, legal name,
VAT number, CRN, national address, your own ID) and add stores (company, code, name, your own ID). The same
rules as [`Zatca::companies()`](./device-import#1-companies-and-stores) apply; adding an existing one changes nothing.

Each row has **Edit**, **Disable / Enable** and **Delete**:

- **Edit company** — name, Arabic name, legal name, CRN, your ID and national address. The VAT number is in
  every device certificate and cannot be changed. Changes apply to invoices issued from now on.
- **Edit store** — code (unique within the company), name and your ID. Onboarded devices keep the old store
  name in their certificate until it is renewed.
- **Disable** — every device of the company / store refuses new invoices (`DeviceUnavailable`, HTTP API `503`)
  until it is enabled again. Retries of invoices already accepted still return their original result.
- **Delete** — only while no device and no invoice belongs to it (otherwise disable it). Deleting a company
  also deletes its stores. Adding the same VAT number or store code again brings the deleted record back.

### Errors & warnings

- **Errors** — open, resolved or all; filter by category, severity, B2B/B2C and code. Each links to its
  invoice.
- **Groups** — identical errors counted once (same workflow, category, code and message pattern), with open
  and total counts and the last occurrence. Shows at a glance whether one cause hit many invoices.
- **Warnings** — accepted with warnings, or documented assumptions of the package.

**Mark as resolved** (one error, or every open error of a group) needs a note. It only acknowledges the
error: it does not retry or change the invoice. To fix an invoice, use the invoice actions. The resolution
is written to the audit log.

### Queue monitor

A snapshot of the backlog: outbox messages pending, due, being processed and dead-lettered, the age of the
oldest, pending per queue; B2B and B2C submissions by status, the oldest open one, and B2C invoices past the
24-hour reporting deadline. A warning appears above `queues.high_water_mark` (default 5000). Reload the
page to refresh.

### Audit history

Every operator action (invoice actions, device registration and onboarding, error resolutions, …) with
time, user, IP and details. Filter by action prefix (e.g. `invoice.`), user ID and subject.
**Verify tamper chain** recomputes the hash chain and reports the first altered entry, if any (same as
`php artisan zatca:audit:verify`).

### Settings

A read-only view of the active configuration (environment, database, tenancy, TLS, storage, queues, B2C,
B2B, corrections, API, dashboard). Change values in `config/zatca.php` or `.env` and deploy, so every change
is reviewed and versioned. Encryption keys, credentials and certificates are never shown.

## Security

- No page shows private keys, certificate secrets or OTPs; they are not even loaded.
- OTPs typed into the dashboard go straight to ZATCA: never flashed back to the form, stored or logged.
- Uploaded device files are deleted from the server right after reading.
- The correction editor keeps your draft in the session when validation fails. With the `cookie` session
  driver (4 KB limit) large invoices cannot be kept; use `file`, `database` or `redis` sessions.
- XML downloads are streamed after a permission check, never from a public URL, with `Cache-Control: no-store`.
- Every form is protected by Laravel's CSRF check (`web` middleware).
- Tenancy applies: users only see the invoices of the current tenant.
- Pages are marked `noindex`.

## Customising

```bash
php artisan vendor:publish --tag=zatca-views    # copies the views to resources/views/vendor/zatca
```

Published views override the package's. They may need updating after package upgrades.

## Roles

Suggested roles (as in the architecture document):

| Permission | Viewer | B2B operator | B2C operator | Accountant | Device admin | Super admin |
|---|:-:|:-:|:-:|:-:|:-:|:-:|
| zatca.dashboard.view | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| zatca.b2b.view | ✓ | ✓ | | ✓ | | ✓ |
| zatca.b2b.retry | | ✓ | | ✓ | | ✓ |
| zatca.b2c.view | ✓ | | ✓ | ✓ | | ✓ |
| zatca.b2c.retry | | | ✓ | ✓ | | ✓ |
| zatca.invoices.edit | | | | ✓ | | ✓ |
| zatca.invoices.correct | | | | ✓ | | ✓ |
| zatca.invoices.reprocess | | | | ✓ | | ✓ |
| zatca.invoices.export | | ✓ | ✓ | ✓ | | ✓ |
| zatca.devices.view | ✓ | ✓ | ✓ | | ✓ | ✓ |
| zatca.devices.onboard | | | | | ✓ | ✓ |
| zatca.devices.renew | | | | | ✓ | ✓ |
| zatca.errors.view | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| zatca.errors.resolve | | ✓ | ✓ | ✓ | ✓ | ✓ |
| zatca.queue.view | | ✓ | ✓ | | ✓ | ✓ |
| zatca.audit.view | | | | ✓ | | ✓ |
| zatca.companies.view | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| zatca.companies.manage | | | | | | ✓ |
| zatca.reports.view | ✓ | ✓ | ✓ | ✓ | | ✓ |
| zatca.settings.manage | | | | | | ✓ |
