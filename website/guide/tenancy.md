# Multi-company and multi-tenant

## Several companies in one application

Register each company with `zatca:taxpayer:register` and give it its own devices. Every invoice is
issued by a device, and the seller block always comes from that device's company. Choosing the device
therefore chooses the company:

```php
Zatca::b2c()->issue($payload, ['device' => $store->zatca_device_id]);
```

Idempotency keys are unique **per company**: two companies can both use invoice number `INV-1`.

This is the recommended setup for most multi-company applications.

## Multi-tenant SaaS (column mode)

```dotenv
ZATCA_TENANCY_MODE=column
```

```php
// config/zatca.php
'tenancy' => [
    'mode' => env('ZATCA_TENANCY_MODE', 'none'),
    'resolver' => App\Zatca\CurrentTenantResolver::class,
],
```

```php
use CodingTriplets\Zatca\Core\Contracts\Tenancy\TenantResolver;

final class CurrentTenantResolver implements TenantResolver
{
    public function current(): ?string
    {
        return tenant()?->getTenantKey();   // your tenancy package
    }
}
```

In `column` mode the package writes the current tenant ID on its records and scopes queries of the
package models to the current tenant (when one is active). Queue workers process submissions by record
ID across all tenants; they do not need a tenant context.

| Mode | Status |
|---|---|
| `none` | Default. Single tenant |
| `column` | Supported as described above |
| `database` | Accepted by the configuration, but the package does not switch connections for you. Point `ZATCA_DB_CONNECTION` at the right database yourself. ⚠ Not tested yet |

::: warning
Never share a device between tenants. Each device's counter and hash chain belong to exactly one company.
:::
