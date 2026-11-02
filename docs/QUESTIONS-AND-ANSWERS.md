# Questions and answers (review of 2026-11-02)

Status: **confirmed** (decisions in "Product owner answers" at the end). Items marked ⚠
depend on ZATCA rules that are not confirmed in writing.

---

## Q1. Device onboarding works only through Artisan commands. Can it be done from code, for many devices at once (e.g. imported from Excel)?

**Today**

| Step | From code? | Bulk? |
|---|---|---|
| Register taxpayer (company + VAT registration + branch) | No, Artisan only (`zatca:taxpayer:register`) | No |
| Register device | No, Artisan only (`zatca:device:register`) | No |
| Onboard device (OTP) | Yes: `DeviceOnboardingService::onboard($device, $otp)` | No (one device per call) |
| Renew certificate | Yes: `CertificateRenewalService` | No |

So bulk use from the host application is missing.

**Proposed change** — a `Zatca::devices()` API that takes plain PHP arrays. The package does not read
Excel itself (that would add a heavy dependency); the host app reads the file with any library (e.g.
`maatwebsite/excel`) and passes the rows.

```php
// 1. Register (no OTP needed). Idempotent: an existing taxpayer/device with the same key is returned.
$taxpayer = Zatca::devices()->registerTaxpayer([
    'name' => 'My Company', 'legal_name' => 'My Company LLC', 'vat_number' => '399999999900003',
    'crn' => '1010010000', 'address' => [...], 'branches' => [['code' => 'RYD', 'name' => 'Riyadh']],
]);

$results = Zatca::devices()->registerMany([
    ['vat_registration' => 1, 'branch' => 'RYD', 'name' => 'POS-01', 'types' => '1100', 'industry' => 'Retail', 'location' => 'Riyadh'],
    ['vat_registration' => 1, 'branch' => 'RYD', 'name' => 'POS-02', 'types' => '0100', ...],
]);   // one result per row: device_id or field errors; one bad row does not stop the others

// 2. Onboard many: device => OTP
$results = Zatca::devices()->onboardMany([
    ['device' => 'POS-01', 'otp' => '123456'],
    ['device' => 'POS-02', 'otp' => '654321'],
]);   // per device: COMPLETED / AWAITING_COMPLIANCE_CHECKS / FAILED + error
```

Also: `validateMany()` (check the rows before registering), and the same functions as commands
(`zatca:devices:import devices.csv`) for teams without code.

**Constraints the design must respect**

- **OTPs are never stored** (security rule). Therefore `onboardMany()` runs **in the calling process**,
  not on a queue (a queued job would write the OTP into Redis/database). Each onboarding calls ZATCA
  several times (CSID + compliance samples + production CSID), roughly 3–10 seconds per device ⚠
  (to be measured). 100 devices ≈ several minutes, so it should be called from a command or a long
  running job of the host app, with `concurrency` (e.g. 5 devices in parallel) as an option.
- OTP validity: the Fatoora portal generates OTPs per EGS unit with a short validity ⚠ (confirm exact
  validity and how many can be generated at once). Rows whose OTP has expired fail individually and can
  be retried with a new OTP; registration is not repeated.

**Decision needed:** confirm the API above, and whether a CSV import command is wanted in addition.

---

## Q2. Discounts: is header-level (document) discount handled?

**Today**

| Discount type | Supported | How |
|---|---|---|
| Line discount (amount) | Yes | `lines[].discount` → line `AllowanceCharge` in the XML |
| Header / document discount (amount) | **Yes** | `allowances[]` → document `AllowanceCharge`; verified live in the sandbox |
| Header / document charge (delivery, service) | Yes | `charges[]` |
| Percentage discount (e.g. 10 %) | **No** | Caller must compute the amount |
| Line discount reason | Partly | Always written as `discount`; no custom reason/code |
| Header discount on an invoice with several VAT rates | Manual | The caller must send one allowance per VAT category (ZATCA requires the category on each allowance) |

So header discount exists, but it is not documented clearly enough and is less convenient than it should be.

**Proposed change**

```php
'lines' => [
    ['name' => 'Shirt', 'quantity' => '2', 'unit_price' => '100', 'discount_percent' => '10', 'discount_reason' => 'Promotion', ...],
],
'allowances' => [
    ['percent' => '5', 'reason' => 'Loyalty', 'vat_category' => 'S', 'vat_rate' => '15'],   // 5 % of the S/15 base
    ['amount' => '20.00', 'reason' => 'Voucher', 'distribute' => true],                       // split across VAT groups pro rata
],
```

- `discount_percent` / `percent`: amount computed by the package (exact decimals, written with
  `MultiplierFactorNumeric` and `BaseAmount` in the XML). Verified 2026-11-02: accepted by the ZATCA
  sandbox on simplified and standard invoices without warnings.
- `discount_reason` / `discount_reason_code` on lines.
- `distribute: true`: a single header discount is split across VAT groups in proportion to their net
  amounts. This is a tax decision, so it is **opt-in**, never automatic.
- A new user-guide section "Discounts" with worked examples (line, header, mixed VAT, percentage).

**Decision needed:** confirm percentage support and the opt-in `distribute` behaviour.

---

## Q3. Is there a cancellation invoice?

**Answer:** ZATCA has **no cancellation document type**. An invoice accepted by ZATCA is cancelled by a
**credit note (381) for the full amount** that references it. This is supported today
(`Zatca::invoices()->creditNote()`), but the caller has to repeat every line by hand.

What is missing and proposed:

| Case | Today | Proposed |
|---|---|---|
| Cancel an accepted invoice completely | Manual credit note with all lines | `Zatca::invoices()->cancel($invoice, $reason)` builds the full credit note from the original lines automatically |
| Partial cancellation / return | `creditNote()` with chosen lines | Unchanged, plus `creditNote($invoice, lines: [line numbers + quantities])` that copies prices from the original |
| Over-crediting (credit notes > invoice total) | Not checked | Refused: the sum of credit notes cannot exceed the original total (minus earlier credits, plus debits) |
| Invoice never signed (`VALIDATION_FAILED`, `READY_FOR_PROCESSING`) | Stays in the list | `discard($invoice, $reason)` → status `CANCELLED`; no ICV was used, nothing goes to ZATCA |
| Invoice **rejected** by ZATCA and not needed any more | Stays `REJECTED` | `abandon($invoice, $reason)` → status `ABANDONED`, kept in history and in the chain ⚠ (ZATCA guidance on abandoning a rejected document is not explicit) |
| "Cancellation" status on the original | None | Original shows `credited_amount` and `fully_credited = true`; event `InvoiceCancelled` |

**Decision needed:** confirm `cancel()`, `discard()`, `abandon()` and the over-credit check.

---

## Q4. Are invoice line items saved?

**Today:** lines are saved, but only inside the JSON:

- `zatca_invoice_versions.payload` — the lines exactly as sent (original and every correction);
- the signed XML on the artifact disk — the legal lines;
- `zatca_invoices` — only header totals (`total_excl_vat`, `vat_total`, `total_incl_vat`).

There is **no line table**, so reports such as "sales per VAT category" or "all invoices containing
item X" need JSON queries, which are slow on large tables.

**Proposed change:** a `zatca_invoice_lines` table, written in the same transaction as the issuance,
one row per line per issuance, with the **calculated** values as they appear in the signed XML:

`issuance_id, invoice_id, line_no, name, item_code (optional), quantity, unit_code, unit_price,
discount_amount, net_amount, vat_category, vat_rate, exemption_reason_code, vat_amount,
amount_incl_vat`

plus `zatca_invoice_vat_breakdown` (one row per VAT group: taxable amount, VAT amount). The dashboard
(M7) uses them for item and VAT reports. Config `database.store_lines` (default `true`) for users who
do not need them. Cost: one multi-row insert per invoice.

Optional `item_code` / `sku` field on lines (stored, not sent to ZATCA unless mapped to
`SellersItemIdentification` ⚠).

**Decision needed:** confirm the two tables and the default `true`.

---

## Q5. What if the user already has their own invoice table, with any name?

**Answer:** that is the expected situation and it works today. The package **never reads or writes the
host application's tables**. Its own tables are prefixed (`zatca_`, configurable via
`ZATCA_TABLE_PREFIX`), so they do not clash with an existing `invoices` table.

The link today is:

| Direction | How |
|---|---|
| Your record → package | Pass your own ID as `external_id` in `issue()` (idempotency key) |
| Package → your record | Store the returned `invoice_id` on your table; events carry `publicId` |

**Proposed improvement** (convenience, optional):

```php
// any model, any table name
class SalesBill extends Model
{
    use \CodingTriplets\Zatca\Laravel\Concerns\HasZatcaInvoices;
}

$result = Zatca::b2c()->issue($payload, ['device' => 1, 'source' => $bill]);

$bill->zatcaInvoice;           // latest ZATCA invoice record
$bill->zatcaInvoices;          // including credit/debit notes
Invoice::forSource($bill)->get();
```

Implemented with two nullable columns on `zatca_invoices` (`source_type`, `source_id`, a polymorphic
link). Events would also carry `sourceType` / `sourceId`, so listeners can update the host record
without a lookup. Nothing changes for users who do not use it.

**Decision needed:** confirm the polymorphic `source` link and trait.

---

## Q6. "I don't want to see Claude in the contributors."

**Checked on 2026-11-02:**

- All commits on GitHub `main` (now 6, up to `48c41df`) are authored and committed by
  `Nirbhay Singh <nirbhay@skyviewads.com>`.
- No commit message contains `Co-Authored-By` or any Claude line.
- The remote has only one branch (`main`) and no tags.
- GitHub's contributors API currently returns an **empty list** for the repository.

So nothing in the repository credits Claude. If the GitHub page still shows Claude:

1. GitHub caches the contributor list from the **old** history (before the earlier commits were
   replaced). It is rebuilt automatically; this can take from hours to a few days after a push.
2. Add `nirbhay@skyviewads.com` to your GitHub account (**Settings → Emails**). Commits are only
   linked to a profile when their email belongs to it, which is also why the list is empty right now.
3. If it still shows after a few days, GitHub Support can clear the cache (Support → "Repository
   contributors graph is incorrect"). Deleting and recreating the repository is the last resort.

From now on: commits never include Claude attribution, and pushes happen only after checking that.

---

## Q7. Order of work

**Proposed:**

1. **M6.1** — the confirmed items from Q1–Q5 (device API + bulk, discounts, cancel/discard/abandon,
   line and VAT tables, source link), with tests, docs and a sandbox run.
2. **M7** — dashboard (Blade + Alpine + Tailwind, prebuilt assets): invoice lists for B2B and B2C,
   invoice detail (versions, issuances, attempts, errors, lines), retry/correct/reissue/approve/credit/
   cancel actions, devices (including bulk import and onboarding screen with OTP entry), errors and
   error groups, queue monitor, audit history, settings; role-based access through Laravel Gate.

M7 builds on M6.1 (line tables, cancel actions, device API), so doing M6.1 first avoids rework.

**Decision needed:** confirm this order.

---

## Product owner answers (2026-11-02)

### A1. Devices — import from an Excel/CSV file path (revised design)

Flow described by the product owner:

1. The host app's user uploads an Excel/CSV file in the host app's interface.
2. The host app first creates the **company** and the **store** (branch) through the package.
3. The host app calls the package with the **file path**; each row says under which `company_id` and
   `store_id` the device is registered.
4. The host app then calls the package to **onboard** the devices.

Revised API:

```php
// 2. company and store from code (no Artisan needed)
$company = Zatca::companies()->create([
    'name' => 'My Company', 'legal_name' => 'My Company LLC', 'vat_number' => '399999999900003',
    'crn' => '1010010000', 'address' => [...],
]);
$store = Zatca::companies()->createStore($company->id, ['code' => 'RYD-01', 'name' => 'Riyadh Mall']);

// 3. devices from a file (local path or ['disk' => 's3', 'path' => 'imports/devices.xlsx'])
$report = Zatca::devices()->import('/path/devices.xlsx');            // .xlsx, .xls* or .csv
$report->created;  $report->skipped;  $report->failed;                // per row: row number, device_id or errors

// 4. onboarding (separately, or in the same call when the file has an OTP column)
$report = Zatca::devices()->onboardMany([['device' => 12, 'otp' => '123456'], ...]);
```

File columns: `company_id, store_id, device_name, invoice_types (1000/0100/1100), industry, location,
serial (optional), environment (optional), otp (optional)`.

- `.csv` is read natively. `.xlsx` needs `openspout/openspout` (small, streaming, low memory),
  declared as a suggested dependency; the package explains how to install it if it is missing.
- Rows are validated first; one bad row never stops the others. Re-importing the same file does not
  create duplicates (key: store + device name).
- Large files are read row by row (streaming), not loaded into memory.
- OTPs are never stored, logged or copied by the package. If the file contains OTPs, onboarding
  runs immediately in the same call, and the host app should delete the uploaded file afterwards.

### A2. Discounts — use zatca-xml-js (zatca-xml-ts) as reference

Checked `wes4m/zatca-xml-js` (`src/zatca/ZATCASimplifiedTaxInvoice.ts`):

| zatca-xml-js | This package |
|---|---|
| `line_item.discounts: [{amount, reason}]`, several per line, each a line `AllowanceCharge` | Will accept the same shape: `lines[].discounts: [{amount, reason, reason_code?}]` (and keep the single `discount`) |
| No header (document) discount | Already supported (`allowances`), kept |
| No percentage discount | Will add `percent` (Q2) |
| Amounts truncated (`toFixedNoRounding`) | Not copied: exact decimals, rounded half-up as ZATCA calculates; truncation can make totals differ from ZATCA's checks |
| Simplified (B2C) only | Same rules for B2B and B2C |

Payloads written for zatca-xml-js (line discounts) can therefore be mapped one to one.

### A3. Line items and own invoice table — confirmed

`zatca_invoice_lines` + `zatca_invoice_vat_breakdown` (on by default) and the polymorphic `source`
link with the `HasZatcaInvoices` trait.

### A4. Order — confirmed

M6.1 (these changes), then M7 (dashboard).

### A5. Final answers

| Point | Decision |
|---|---|
| `company_id` / `store_id` in the device file | **Both accepted**: package IDs, or the host app's own IDs saved as `external_ref` on the package company/store |
| Cancellation | **All four**: `cancel()`, `discard()`, `abandon()` (⚠ until ZATCA confirms), over-credit check |
| OTP column in the device file | **Optional**: with OTPs the devices are onboarded during the import; otherwise later with `onboardMany()` |

All points are confirmed; implementation in M6.1, then M7.
