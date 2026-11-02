# Dashboard

A web dashboard for your operators: invoice lists, invoice details and the everyday actions (retry,
reissue, approve, cancel, discard, abandon). It is plain Blade with one prebuilt CSS file — no JavaScript
and no build step in your application.

::: info Being built in parts
**Available now (M7a):** overview, B2B and B2C lists, invoice detail, actions, signed/cleared XML download,
permissions.
**Coming:** devices and bulk import screen, errors and error groups, queue monitor, audit, settings (M7b);
correction editor, credit/debit note forms, charts, reports and exports (M7c).
:::

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

The other permissions of the [permissions matrix](#roles) (`zatca.devices.*`, `zatca.errors.*`,
`zatca.queue.view`, `zatca.audit.view`, `zatca.settings.manage`, …) are used by the pages that come in
M7b/M7c.

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

## Security

- No page shows private keys, certificate secrets or OTPs; they are not even loaded.
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
