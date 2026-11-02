# HTTP API

The package can expose a small JSON API, for example for POS terminals or other services that are not
written in PHP. It is **off by default**.

```dotenv
ZATCA_API_ENABLED=true
ZATCA_API_PREFIX=api/zatca
```

::: danger Add authentication
The routes use only the middleware in `zatca.api.middleware` (default `['api']`). Add your own
authentication, for example:

```php
// config/zatca.php
'api' => [
    'enabled' => env('ZATCA_API_ENABLED', false),
    'prefix' => env('ZATCA_API_PREFIX', 'api/zatca'),
    'middleware' => ['api', 'auth:sanctum'],
],
```

Anyone who can call these endpoints can issue legally binding invoices in your company's name.
:::

## Endpoints

| Method | Path | Purpose |
|---|---|---|
| `POST` | `/api/zatca/b2c/invoices` | Issue a simplified invoice |
| `POST` | `/api/zatca/b2b/invoices` | Issue a standard invoice (signed now, cleared on the queue) |
| `GET` | `/api/zatca/invoices/{invoice_id}` | Current state of an invoice |

### Headers

| Header | Required | Meaning |
|---|---|---|
| `X-Zatca-Device` | Yes | Device ID or device UUID |
| `Idempotency-Key` | No | Idempotency key (default: the payload's `invoice_number`) |
| `Content-Type` | Yes | `application/json` |

The body is the [invoice payload](./payload).

## Example

```bash
curl -X POST https://shop.example.com/api/zatca/b2c/invoices \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Zatca-Device: 1" \
  -H "Idempotency-Key: ORDER-98765" \
  -d '{"invoice_number":"POS-000124","lines":[{"name":"Coffee","quantity":"1","unit_price":"12.50","vat_category":"S","vat_rate":"15"}]}'
```

```json
{
  "status": true,
  "message": "invoice queued",
  "qr": "AR1NYXhpbXVt...",
  "counter": 10453,
  "uuid": "8a1f6e8b-6a4e-4a3c-9d1c-7e1f0c2b3a4d",
  "invoice_id": "01M6E2A3B4C5D6E7F8G9H0J1K2",
  "invoice_hash": "q9h3...=",
  "processing_status": "QUEUED",
  "reporting_deadline": "2026-11-03T15:02:11Z"
}
```

## Status codes

| Code | Meaning | Client should |
|---|---|---|
| `201` | Invoice created | Print the QR |
| `200` | Same invoice sent again; original result returned | Treat as success |
| `400` | Body is not a JSON object | Fix the request |
| `404` | Invoice not found (GET) | — |
| `409` | Same invoice number / key with a different payload. Body contains `invoice_id` of the existing invoice | Use a new number, or look up the existing invoice |
| `422` | Validation failed (`errors` list), or device header missing | Fix the data |
| `429` | Too many concurrent requests (`Retry-After: 1`) | Retry after the given seconds, same `Idempotency-Key` |
| `503` | Queue saturated (`Retry-After: 5`) or device unavailable (`Retry-After: 60`) | Retry later, same `Idempotency-Key` |

Always retry with the **same** `Idempotency-Key`: you get the original result and never a second invoice.

## Load shedding

To keep response times low under heavy load, the API refuses work early instead of letting requests
queue up and time out (502/504 at the proxy):

- more than `zatca.performance.max_in_flight_issuance` (default 200) requests issuing at the same time → `429`;
- more than `zatca.queues.high_water_mark` (default 5000) submissions waiting → `503`.
