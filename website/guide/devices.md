# Taxpayers and devices

Every invoicing unit (POS terminal, ERP instance, web shop) is a **device** with its own key,
certificates, counter and hash chain. A device must be onboarded with ZATCA before it can issue invoices.

::: tip From your code or an Excel file
This page uses the Artisan commands. To register companies, stores and many devices from your
application (e.g. an uploaded Excel file), see [Devices from code and Excel](./device-import).
:::

## 1. Register the taxpayer

```bash
php artisan zatca:taxpayer:register \
    --name="Maximum Speed" --name-ar="ماكسيمم سبيد" \
    --legal-name="Maximum Speed Tech Supply LTD" \
    --vat=399999999900003 --crn=1010010000 \
    --branch-code=RYD --branch-name="Riyadh Branch" \
    --street="Prince Sultan" --building=2322 --district=Al-Murabba --city=Riyadh --postal-code=23333
```

| Option | Meaning |
|---|---|
| `--vat` | 15-digit VAT number, starting and ending with 3 |
| `--legal-name` | Legal name as registered with ZATCA. Printed on every invoice |
| `--crn` | Commercial registration number (seller ID, scheme `CRN`) |
| `--group-tin` | VAT groups only (11th digit of the VAT number is `1`): the member's 10-digit TIN |
| Address options | Saudi national address: building number 4 digits, postal code 5 digits |

The seller block of every invoice is built from this registration. A `seller` sent with an invoice is
ignored, so one client can never issue in another company's name.

## 2. Register a device

```bash
php artisan zatca:device:register --vat-registration=1 --branch=1 --name=POS-RYD-01 \
    --types=1100 --industry="Supply activities" --location="RRRD2929"
```

| `--types` | The device may issue |
|---|---|
| `1000` | B2B (standard) only |
| `0100` | B2C (simplified) only |
| `1100` | Both |

The EGS serial number is generated as `1-<provider>|2-<model>|3-<device uuid>` unless you pass `--serial`.
`--environment` defaults to `ZATCA_ENVIRONMENT`.

## 3. Onboard

Generate an OTP in the Fatoora portal for the device's environment (sandbox: always `123345`), then:

```bash
php artisan zatca:device:onboard 1
```

The OTP is read as hidden input, sent straight to ZATCA and never stored or logged. The package then:

1. generates a secp256k1 private key (stored encrypted) and a certificate signing request;
2. requests the **compliance CSID**;
3. runs ZATCA's **compliance checks**: a sample invoice, credit note and debit note per workflow the
   device supports, on a separate sample chain (your real chain is not touched);
4. requests the **production CSID** and marks the device `ACTIVE`.

If a compliance check fails, the device stops at `AWAITING_COMPLIANCE_CHECKS` with ZATCA's errors.
Fix the cause (usually the seller address) and continue without a new OTP:

```bash
php artisan zatca:device:onboard 1 --resume
```

From code:

```php
use CodingTriplets\Zatca\Laravel\Devices\DeviceOnboardingService;

$onboarding = app(DeviceOnboardingService::class)->onboard($device, $otp, actorId: auth()->id());
$onboarding->status; // COMPLETED | AWAITING_COMPLIANCE_CHECKS | FAILED
```

## 4. Certificate renewal

Production certificates expire. A daily check (03:15) moves devices to `RENEWAL_DUE` 30 days before
expiry and fires `CertificateExpiring` at 30, 14, 7 and 1 days. Renew with a new OTP:

```bash
php artisan zatca:device:renew 1
```

The device keeps issuing with the old certificate until the new one is stored. If renewal fails,
nothing changes (`RENEWAL_FAILED`) and you can try again.

## Device statuses

| Status | Can issue? | Meaning |
|---|---|---|
| `REGISTERED` | No | Created, not onboarded |
| `KEY_GENERATED`, `COMPLIANCE_CSID_ISSUED`, `COMPLIANCE_CHECKING`, `COMPLIANCE_PASSED` | No | Onboarding in progress (or paused waiting for compliance checks) |
| `ONBOARDING_FAILED` | No | ZATCA refused a step (for example a wrong OTP). Start again with a new OTP |
| `ACTIVE` | Yes | Ready |
| `RENEWAL_DUE` | Yes | Certificate expires within 30 days |
| `RENEWING` | Yes | Renewal in progress; the current certificate is still used |
| `RENEWAL_FAILED` | Yes, until expiry | Last renewal failed |
| `EXPIRED` | No | Certificate expired: renew |
| `SUSPENDED`, `DECOMMISSIONED` | No | Disabled |

The onboarding record (not the device) shows `AWAITING_COMPLIANCE_CHECKS` when compliance checks failed
and `--resume` is needed.

An invoice sent to a device that cannot issue is refused with `DeviceUnavailable` (HTTP API: 503) and
no counter is used.

## How many devices?

One device issues one document at a time (the hash chain is sequential). Plan one device per POS
terminal, and several devices for high-volume back-office or web-shop invoicing. See
[Performance and scaling](./scaling).
