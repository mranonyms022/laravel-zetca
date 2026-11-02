# Invoice payload

The same payload format is used by `Zatca::b2c()`, `Zatca::b2b()`, corrections and the HTTP API. Keys
are snake_case. Amounts are **decimal strings** (numbers are accepted); prices **exclude VAT**.

## Minimal B2C invoice

```php
[
    'invoice_number' => 'POS-000123',
    'lines' => [
        ['name' => 'Coffee', 'quantity' => '2', 'unit_price' => '12.50', 'vat_category' => 'S', 'vat_rate' => '15'],
    ],
]
```

## Minimal B2B invoice

```php
[
    'invoice_number' => 'INV-2026-0042',
    'supply_date' => '2026-11-01',
    'buyer' => [
        'name' => 'Fatoora Samples LTD',
        'vat_number' => '399999999800003',
        'address' => [
            'street' => 'Salah Al-Din', 'building_number' => '1111', 'district' => 'Al-Murooj',
            'city' => 'Riyadh', 'postal_code' => '12222', 'country' => 'SA',
        ],
    ],
    'lines' => [
        ['name' => 'Consulting', 'quantity' => '10', 'unit_price' => '500.00', 'unit_code' => 'HUR', 'vat_category' => 'S', 'vat_rate' => '15'],
    ],
]
```

## Document fields

| Field | Required | Description |
|---|---|---|
| `invoice_number` | Yes | Your invoice number (IRN). Also the default idempotency key |
| `external_invoice_id` | No | Idempotency key if different from `invoice_number` (max 191 characters) |
| `type` | No | `invoice` (default, 388), `credit_note` (381), `debit_note` (383). Use the [notes API](./notes) for notes |
| `issued_at` | No | ISO 8601 date-time. Without an offset it is read as Asia/Riyadh time. Default: now. Must not be in the future |
| `currency` | No | `SAR` (only SAR is supported) |
| `payment_means_code` | No | UNTDID 4461 code: `10` cash (default), `30` credit, `42` bank transfer, `48` card |
| `supply_date` | B2B: recommended | Actual delivery date (`YYYY-MM-DD`) |
| `supply_end_date` | No | End of the supply period. Requires `supply_date` |
| `subtype` | No | Flags: `third_party`, `nominal`, `export`, `summary`, `self_billed` (`true`/`false`) |
| `notes` | No | List of free-text notes |
| `billing_reference` | Notes only | Original invoice number. Set automatically by `creditNote()` / `debitNote()` |
| `reason` | Notes only | Reason for the note (KSA-10). Set automatically by the notes API |
| `seller` | Ignored | Always taken from the device's registered taxpayer |
| `uuid`, `icv`, `counter`, `pih`, `invoice_hash`, `qr` | Ignored | Assigned by the package |

## Buyer

Optional for B2C, required for B2B.

| Field | Description |
|---|---|
| `name` | Buyer name (B2B: required) |
| `vat_number` | 15 digits, starting and ending with 3. Must differ from the seller's |
| `identification.scheme` / `identification.value` | Other buyer ID when there is no VAT number |
| `address.street`, `building_number`, `district`, `city`, `postal_code`, `country` | Saudi address: building number 4 digits, postal code 5 digits. `country` is ISO alpha-2 |

Buyer ID schemes: `TIN` (tax ID), `CRN` (commercial registration), `MOM` (MOMRAH licence), `MLS` (MHRSD
licence), `700` (700 number), `SAG` (MISA licence), `NAT` (national ID), `GCC` (GCC ID), `IQA` (Iqama),
`PAS` (passport), `OTH` (other).

For B2B the buyer needs a VAT number **or** another ID (rule BR-KSA-81). ZATCA treats a missing ID as a
warning; the package rejects it before signing by default (`zatca.b2b.require_buyer_identification`).

## Lines

| Field | Required | Description |
|---|---|---|
| `name` | Yes | Item name |
| `quantity` | Yes | Positive decimal |
| `unit_price` | Yes | Net unit price, excluding VAT |
| `unit_code` | No | UN/ECE rec 20 unit, default `PCE` (piece). Examples: `HUR` hour, `KGM` kilogram |
| `item_code` / `sku` | No | Your item code (stored in the lines table; not sent to ZATCA) |
| `discount` | No | Discount **per unit** (price discount, BT-147). Net unit price = `unit_price` − `discount` |
| `discounts` | No | Line discounts as **totals for the line** (one or more): see [Discounts](#discounts) |
| `vat_category` | Yes | `S` standard, `Z` zero-rated, `E` exempt, `O` out of scope |
| `vat_rate` | Yes | Percentage: `15` (not `0.15`). `0` for Z, E and O |
| `exemption_reason_code` | Z, E, O | `VATEX-…` code (see below) |
| `exemption_reason` | Z, E, O | Reason text |

### VAT exemption codes

| Category | Allowed codes |
|---|---|
| `Z` zero-rated | `VATEX-SA-32`, `VATEX-SA-33`, `VATEX-SA-34-1` … `VATEX-SA-34-5`, `VATEX-SA-35`, `VATEX-SA-36`, `VATEX-SA-EDU`, `VATEX-SA-HEA`, `VATEX-SA-MLTRY` |
| `E` exempt | `VATEX-SA-29`, `VATEX-SA-29-7`, `VATEX-SA-30` |
| `O` out of scope | `VATEX-SA-OOS` |

`VATEX-SA-EDU` and `VATEX-SA-HEA` on a simplified invoice require the buyer's national ID
(`identification.scheme = NAT`).

### Missing VAT category

If `vat_category` is missing and `vat_rate` is `15`, `S` is assumed and a warning `INFERRED` is returned
(`zatca.b2c.infer_standard_vat_category`). A missing category with rate `0` is always an error, because
0 % can mean zero-rated, exempt or out of scope.

## Discounts

There are three kinds. All of them can be combined on one invoice.

| Kind | Where | Meaning | XML |
|---|---|---|---|
| Unit discount | `lines[].discount` | Amount off **each unit** | `cac:Price/cac:AllowanceCharge` (BT-147) |
| Line discount | `lines[].discounts[]` | Amount or % off **the line total** | `cac:InvoiceLine/cac:AllowanceCharge` (BG-27) |
| Header discount | `allowances[]` | Amount or % off **the whole invoice** (per VAT group) | `cac:AllowanceCharge` (BG-20) |

### Line discounts

```php
'lines' => [[
    'name' => 'Shirt', 'quantity' => '3', 'unit_price' => '100.00', 'vat_category' => 'S', 'vat_rate' => '15',
    'discounts' => [
        ['amount' => '20.00', 'reason' => 'Promotion'],
        ['percent' => '10', 'reason' => 'Member discount', 'reason_code' => '95'],
    ],
]],
```

Each entry has `amount` **or** `percent` (0–100, up to 2 decimals), and a `reason` and/or `reason_code`
(UNTDID 5189, e.g. `95` discount). A percentage is calculated from the line amount
(quantity × net unit price) unless you send `base_amount`. If you send both `percent` and `amount`, the
amount must equal base × percent (rule BR-KSA-EN16931-03). The discounts of a line may not exceed the line
amount.

The `discounts` shape is the one used by the `zatca-xml-js` library, so payloads built for it can be sent
as they are. Unlike that library, the package writes them as line allowances (what the ZATCA standard
defines for line-total discounts) and rounds half-up instead of truncating.

### Header (document) discounts and charges

```php
'allowances' => [
    ['amount' => '5.00', 'reason' => 'Loyalty discount', 'vat_category' => 'S', 'vat_rate' => '15'],
    ['percent' => '2.5', 'reason' => 'Early payment', 'vat_category' => 'S', 'vat_rate' => '15'],
],
'charges' => [
    ['amount' => '10.00', 'reason' => 'Delivery', 'reason_code' => 'FC', 'vat_category' => 'S', 'vat_rate' => '15'],
],
```

Each needs `amount` or `percent`, a `reason` or `reason_code`, and the VAT category and rate it applies to.
A `percent` without `base_amount` is calculated from the net total of the lines in that VAT group.

**Mixed VAT rates.** ZATCA needs each document allowance to belong to one VAT group. If your discount is
for the whole basket, send `'distribute' => true` instead of `vat_category`/`vat_rate`: the package splits
it over the VAT groups in proportion to their net amounts (an amount is split pro rata with the last
halala on the last group; a percentage is applied to each group). This produces one allowance per group.

```php
'allowances' => [['amount' => '33.33', 'reason' => 'Voucher', 'distribute' => true]],
```

## How totals are calculated

- All arithmetic is exact decimal arithmetic (no floating point), rounded half-up to 2 decimals.
- Line amount = quantity × (unit price − unit discount), rounded; line net = line amount − line discounts.
- Invoice net = sum of line nets − header allowances + header charges.
- VAT is calculated per VAT group (category + rate + exemption code) on the group total, and a VAT
  breakdown is always written.
- The totals you see in the response and in the database (`total_excl_vat`, `vat_total`,
  `total_incl_vat`) are the ones in the signed XML.

## Not supported yet

- `prepaid_amount` (advance payment adjustments) is rejected with code `UNSUPPORTED`.
- Currencies other than SAR.

## Validation

All problems are reported at once, each with the field path and the ZATCA rule ID where one applies:

```json
{
  "status": false,
  "message": "validation failed",
  "errors": [
    {"field": "lines.0.vat_category", "code": "BR-KSA-CL-04", "message": "Category Z requires an exemption reason code (VATEX-SA-32, ...)."},
    {"field": "buyer.address.postal_code", "code": "BR-KSA-67", "message": "Saudi postal codes have exactly 5 digits."}
  ]
}
```

To validate without creating anything:

```php
$result = Zatca::b2c()->validate($payload);
$result->isValid();
$result->errors();
$result->warnings();
```

See the full list in [Validation rules](/reference/validation-rules).
