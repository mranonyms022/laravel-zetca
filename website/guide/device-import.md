# Devices from code and Excel

Everything the `zatca:*` setup commands do is available from PHP, for one device or thousands. The usual
flow in your application:

1. Create the company and its stores (once).
2. Your user uploads an Excel/CSV file with the devices in your own interface.
3. Your backend passes the file path to the package: the devices are registered.
4. Onboard them with the OTPs from the Fatoora portal — in the same file or later.

## 1. Companies and stores

```php
use CodingTriplets\Zatca\Laravel\Facades\Zatca;

$company = Zatca::companies()->create([
    'external_ref' => $myCompany->id,          // your own ID (optional)
    'name' => 'Maximum Speed',
    'name_ar' => 'السرعة القصوى',
    'legal_name' => 'Maximum Speed Tech Supply LTD',
    'vat_number' => '399999999900003',
    'crn' => '1010010000',
    'address' => ['street' => 'Prince Sultan', 'building_number' => '2322', 'district' => 'Al-Murabba',
                  'city' => 'Riyadh', 'postal_code' => '23333'],
    'stores' => [
        ['code' => 'RYD', 'name' => 'Riyadh Branch', 'external_ref' => $myStore->id],
    ],
]);

Zatca::companies()->createStore($company, ['code' => 'JED', 'name' => 'Jeddah Branch', 'external_ref' => 77]);
```

Both are idempotent: calling them again with the same `external_ref`, VAT number or store code returns the
existing record.

## 2. The device file

CSV (UTF-8, `,` `;` or tab separated) works out of the box. For `.xlsx` / `.ods` install
`composer require openspout/openspout`. The first row holds the column names (case and spaces don't matter:
`Store ID` = `store_id`).

| Column | Required | Description |
|---|---|---|
| `company_id` | Yes | Your company ID (`external_ref`) or the package's company ID |
| `store_id` | Yes¹ | Your store ID, the store `code`, or the package's branch ID. Aliases: `branch_id`, `store` |
| `name` | Yes | Device name / asset number, unique per store. Alias: `device_name` |
| `invoice_types` | No | `1100` both (default), `1000` B2B only, `0100` B2C only. `100` from Excel is read as `0100` |
| `industry` | Yes | Business category, e.g. `Retail` |
| `location` | Yes | Branch address or website |
| `vat_number` | No | Only when the company has several VAT registrations |
| `environment` | No | `sandbox`, `simulation`, `production` (default: `zatca.environment`) |
| `serial` | No | EGS serial `1-…\|2-…\|3-…` (generated when empty) |
| `otp` | No | Fatoora OTP: the device is onboarded right away |

¹ Not needed for a VAT group registration.

**IDs.** By default (`ids: auto`) a value is looked up as your own ID first, then as a package ID. Use
`'ids' => 'external'` or `'ids' => 'package'` to allow only one of them.

## 3. Register

```php
// a file on a local path …
$report = Zatca::devices()->import(storage_path('app/uploads/devices.xlsx'), ['actor_id' => auth()->id()]);
// … or on a filesystem disk (S3 etc.)
$report = Zatca::devices()->import(['disk' => 's3', 'path' => 'uploads/devices.csv']);

// or rows you built yourself
$report = Zatca::devices()->registerMany([
    ['company_id' => 12, 'store_id' => 'RYD', 'name' => 'POS-01', 'industry' => 'Retail', 'location' => 'Riyadh'],
]);

// one device
$device = Zatca::devices()->register([...]);

// check a file first, without writing anything
$check = Zatca::devices()->validateMany($path);
```

Every row is handled on its own — one bad row never stops the others — and the report tells you what
happened to each:

```php
$report->counts();      // ['created' => 48, 'existing' => 2, 'failed' => 1]
$report->hasFailures();
$report->rows();        // [['row' => 3, 'status' => 'failed', 'device_id' => null, 'name' => 'POS-03',
                        //   'errors' => ['store [NOPE] was not found in company #1 …']], …]
$report->toArray();     // for a JSON response
```

Row statuses: `created`, `existing` (already registered — importing the same file twice is safe),
`failed`, `onboarded`, `awaiting_compliance_checks`, `onboarding_failed`, and `valid` (validate only).

## 4. Onboard

With an `otp` column, each row is onboarded right after it is registered. Otherwise, later:

```php
$report = Zatca::devices()->onboardMany([
    ['device' => 15, 'otp' => '123456'],          // package ID, device UUID or name
    ['device' => 'POS-02', 'otp' => '654321'],
]);

Zatca::devices()->onboard($deviceId, $otp);       // one device
Zatca::devices()->resume($deviceId);              // after failed compliance checks (no OTP)
```

Onboarding calls ZATCA (a few seconds per device), in the request that calls it. For large batches run
the import from a job or command, not a web request.

::: tip OTPs are never stored
OTPs are used once and live only in memory for the duration of the call: they are not written to the
database, the report, the queue or the logs. That is why onboarding is not queued. Delete the uploaded
file after the import if it contains OTPs.
:::

## Artisan

The same import is available as a command:

```bash
php artisan zatca:devices:import storage/app/devices.csv --validate   # check only
php artisan zatca:devices:import storage/app/devices.csv
php artisan zatca:devices:import uploads/devices.xlsx --disk=s3 --ids=external --no-onboard
```
