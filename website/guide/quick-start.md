# Quick start (sandbox)

This walkthrough issues a simplified (B2C) invoice and reports it to ZATCA's **developer portal**, in
about ten minutes. Nothing here is legally reported.

## 1. Configure the sandbox

```dotenv
ZATCA_ENVIRONMENT=sandbox
ZATCA_ENCRYPTION_KEY=base64:...   # see Installation
QUEUE_CONNECTION=database         # or redis
```

```bash
php artisan queue:table && php artisan migrate   # only if you use the database queue driver (Laravel 9/10)
php artisan migrate
```

## 2. Register your company and a device

```bash
php artisan zatca:taxpayer:register \
    --name="Maximum Speed" --legal-name="Maximum Speed Tech Supply LTD" \
    --vat=399999999900003 --crn=1010010000 \
    --branch-code=RYD --branch-name="Riyadh Branch" \
    --street="Prince Sultan" --building=2322 --district=Al-Murabba --city=Riyadh --postal-code=23333

php artisan zatca:device:register --vat-registration=1 --branch=1 --name=POS-01 \
    --types=1100 --industry="Retail" --location="Riyadh"
```

`--types=1100` lets the device issue both B2B and B2C invoices.

## 3. Onboard the device

```bash
php artisan zatca:device:onboard 1
# OTP from the Fatoora portal (6 digits): 123345
```

The sandbox OTP is always `123345`. The command creates the key, requests the certificates, runs ZATCA's
compliance checks and activates the device. Check it:

```bash
php artisan zatca:devices
```

## 4. Issue an invoice

```php
use CodingTriplets\Zatca\Laravel\Facades\Zatca;

$result = Zatca::b2c()->issue([
    'invoice_number' => 'POS-000001',
    'lines' => [
        ['name' => 'Coffee', 'quantity' => '2', 'unit_price' => '12.50', 'vat_category' => 'S', 'vat_rate' => '15'],
    ],
], ['device' => 1]);

$result->toArray();
```

```json
{
  "status": true,
  "message": "invoice queued",
  "qr": "AR1NYXhpbXVtIFNwZWVkIFRlY2ggU3VwcGx5IExURAIPMzk5...",
  "counter": 1,
  "uuid": "540ce75e-410a-4afa-8630-e603d05a6f13",
  "invoice_id": "01M6E1Y8WXFJ0RK6YXMP4JN9WX",
  "invoice_hash": "I5/YMabWxbVkgy1TisyXDP6qiqDrzTlGwgdQ0+16WdE=",
  "processing_status": "QUEUED",
  "reporting_deadline": "2026-11-03T14:57:29Z"
}
```

The answer comes back without waiting for ZATCA. Print `qr` as a QR code on the receipt.

## 5. Let the worker report it

```bash
php artisan queue:work --queue=zatca-b2c-reporting,zatca-b2c-retry --stop-when-empty
```

Then look at the invoice:

```bash
php artisan zatca:invoice show 01M6E1Y8WXFJ0RK6YXMP4JN9WX
```

`processing` is now `REPORTED`. In the sandbox you will also see the ZATCA warning
`certificate-issuer-name`; that is expected there (see [Troubleshooting](./troubleshooting#sandbox-warning-certificate-issuer-name)).

## Next steps

- [Test plan](./testing): try every feature step by step in the sandbox.

- [Invoice payload](./payload): every field you can send.
- [B2B invoices](./b2b): clearance for business customers.
- [Rejections and corrections](./corrections): what to do when ZATCA says no.
- [Going live](./going-live): simulation and production.
