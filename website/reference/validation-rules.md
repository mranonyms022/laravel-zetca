# Validation rules

Rules checked **before** a counter is used or anything is signed. Rule IDs follow ZATCA's XML
Implementation Standard and EN 16931 where one exists. ZATCA validates more rules on its side; their
errors appear on rejected invoices.

## Document

| Code | Field | Rule |
|---|---|---|
| `BR-02` | `invoice_number` | Required |
| `BR-KSA-05` | `type` | `invoice`, `credit_note`, `debit_note` (388, 381, 383) |
| `WORKFLOW` | `workflow` | `b2b` or `b2c`, matching the issuer used |
| `BR-KSA-04` | `issued_at` | Not in the future |
| `BR-KSA-07` | `subtype` | Export only for B2B; export cannot be self-billed |
| `BR-KSA-16` | `payment_means_code` | UNTDID 4461 code |
| `BR-KSA-35` | `supply_date` | Required when `supply_end_date` is given |
| `BR-KSA-36` | `supply_end_date` | Not before `supply_date` |
| `KSA-24` | `supply_end_date` | Summary invoices need both supply dates |
| `BR-KSA-56` | `billing_reference` | Required for credit/debit notes |
| `BR-KSA-17` | `reason` | Required for credit/debit notes |
| `BR-16` | `lines` | At least one line |
| `BR-CO-13` | `allowances` | Allowances not above the invoice amount; no negative taxable amount per category |
| `BR-CO-16` | `prepaid_amount` | Not above the total |
| `UNSUPPORTED` | `prepaid_amount` | Advance payments not supported yet |
| `FORMAT` | any | Decimal numbers (max 6 decimals), ISO 8601 dates |

## Seller (from the taxpayer registration)

| Code | Rule |
|---|---|
| `BR-KSA-39` | VAT number: 15 digits, starts and ends with 3 |
| `BR-KSA-08` | ID scheme one of CRN, MOM, MLS, 700, SAG, OTH; alphanumeric value |
| `BR-KSA-09` | Address fields required |
| `BR-KSA-37` | Building number: 4 digits |
| `BR-KSA-66` | Postal code: 5 digits |

## Buyer

| Code | Rule |
|---|---|
| `BR-KSA-42` | B2B: buyer and buyer name required |
| `BR-KSA-44` | VAT number: 15 digits, starts and ends with 3 |
| `BR-CUSTOM-VALIDATION-01` | Buyer VAT number differs from the seller's |
| `BR-KSA-46` | Export invoices: no buyer VAT number |
| `BR-KSA-81` | B2B: VAT number or another ID (error by default, configurable) |
| `BR-KSA-14` | ID scheme one of TIN, CRN, MOM, MLS, 700, SAG, NAT, GCC, IQA, PAS, OTH; alphanumeric value |
| `BR-KSA-63` | Address fields required (when an address is given) |
| `BR-KSA-37` / `BR-KSA-67` | Building number 4 digits / postal code 5 digits (Saudi addresses) |
| `BR-CL-14` | Country: ISO 3166 alpha-2 |
| `BR-KSA-49` | B2C: `VATEX-SA-EDU` / `VATEX-SA-HEA` need the buyer's national ID (`NAT`) |

## Lines

| Code | Field | Rule |
|---|---|---|
| `BR-25` | `name` | Required |
| `BR-22` | `quantity` | Greater than zero (use a credit note for returns) |
| `BR-27` | `unit_price`, `discount` | Price not negative; discount between 0 and the line amount |
| `BR-CL-18` | `vat_category` | Required; S, Z, E or O |
| `BR-S-05` | `vat_rate` | Required; percentage (15, not 0.15); greater than 0 for S |
| `BR-S-10` | `exemption_reason_code` | Not allowed for S |
| `BR-KSA-CL-04` | `exemption_reason_code` | Required for Z, E, O; must be valid for the category |

## Warnings

| Code | Meaning |
|---|---|
| `INFERRED` | VAT category `S` was assumed for a 15 % line without a category |
| `BR-KSA-81` | Buyer without ID, when `b2b.require_buyer_identification = false` |
