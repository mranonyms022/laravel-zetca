# Troubleshooting and FAQ

## Installation and setup

### `cURL error 60: SSL certificate problem`

PHP has no CA bundle (common on Windows/WAMP). Download `cacert.pem` from the curl website and set
`ZATCA_CA_BUNDLE=/path/to/cacert.pem`. Do not disable TLS verification.

### The package refuses to boot: "zatca.environment must be …"

`ZATCA_ENVIRONMENT` must be `sandbox`, `simulation` or `production`.

### Tables use MyISAM

The package forces InnoDB when it creates its tables. If you created them by hand or converted them,
convert them back: `ALTER TABLE zatca_invoice_counters ENGINE=InnoDB;` (all `zatca_*` tables).

## Onboarding

### `Invalid-OTP` / onboarding `FAILED`

OTPs are valid for a short time and only once, and only for the environment they were generated in.
Generate a new one and run `zatca:device:onboard` again.

### Stopped at `AWAITING_COMPLIANCE_CHECKS`

A compliance sample was rejected, usually because of the seller address (4-digit building number,
5-digit postal code, district) or the VAT number. Fix the taxpayer data and run
`zatca:device:onboard <id> --resume`. No new OTP is needed.

## Issuing

### `DeviceUnavailable`

The device is not `ACTIVE` (or `RENEWAL_*`), its certificate expired, or it is not registered for this
workflow (`--types`). Check `php artisan zatca:devices`.

### `DuplicateInvoice` (HTTP 409)

The same invoice number (or idempotency key) was used with a different payload. Use a new invoice number,
or fetch the existing invoice with the `invoice_id` in the response.

### Validation error on a 0 % line

Zero-rated, exempt and out-of-scope lines need `vat_category` (`Z`, `E` or `O`), an
`exemption_reason_code` and an `exemption_reason`. See [VAT exemption codes](./payload#vat-exemption-codes).

### Requests time out when many hit the same device

One device issues one document at a time. Use more devices. See [Performance and scaling](./scaling).

## Submission

### Invoices stay `QUEUED`

No worker listens on the ZATCA queues, or the scheduler does not run. Start
`php artisan queue:work --queue=zatca-b2c-reporting,…` and check the cron entry. With `QUEUE_CONNECTION=sync`,
run `php artisan zatca:outbox:relay`.

### Invoices go to `MANUAL_REVIEW` with an authentication error

ZATCA refused the device's credentials (HTTP 401). The certificate may be revoked or expired, or the
device was onboarded in another environment. Check the device, renew if needed, then
`zatca:invoice retry <id>`.

### Sandbox warning `certificate-issuer-name`

ZATCA's developer portal returns a production certificate that does not match the device key. In the
sandbox only, the package signs with the compliance certificate instead and records the warning
`SANDBOX_SIGNING_CERTIFICATE`; ZATCA then answers with the warning `certificate-issuer-name`. This is
expected in the sandbox and does not happen in simulation or production.

### ZATCA says `invoice-previous-hash` / ICV problems

This should not happen: the package allocates counters and hashes under locks. Run
`php artisan zatca:chain:verify --device=<id> --deep` and open an issue with the output (no keys or XML).
Do not edit `zatca_invoice_counters` or issuances by hand.

## FAQ

**Can I send B2B and B2C invoices from the same device?**
Yes, if the device was registered with `--types=1100`. They share the device's counter and chain.

**Can I change an invoice after ZATCA accepted it?**
No. Issue a [credit or debit note](./notes).

**Can I delete a rejected invoice?**
No. Correct and reissue it, so the history stays complete.

**Do I have to store the XML myself?**
No. The package stores the signed XML (and the cleared XML for B2B) on the configured disk.

**Does the package generate PDF invoices?**
Not yet. Use the totals, lines and QR to render your own document.

**Which ZATCA documents does the package follow?**
ZATCA's E-Invoicing Detailed Technical Guidelines, the XML Implementation Standard, the Security Features
Implementation Standards, the Data Dictionary, the Fatoora API documentation and ZATCA's SDK samples.
Points not settled by those documents are marked as pending in the configuration.
