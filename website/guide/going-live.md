# Going live

## 1. Sandbox (developer portal)

Everything from the [quick start](./quick-start). Test your real invoice shapes: every VAT category you
use, discounts, B2B buyers, credit notes. Fix all validation errors and ZATCA warnings.

## 2. Simulation

ZATCA's simulation environment uses your real taxpayer data and real OTPs from the Fatoora portal
(simulation section), but nothing is legally reported.

```dotenv
ZATCA_ENVIRONMENT=simulation
```

Register the devices again with `--environment=simulation` and onboard them with a simulation OTP. Run a
realistic day of traffic, including failures (stop the workers for an hour, then start them again).

## 3. Production

```dotenv
ZATCA_ENVIRONMENT=production
```

Register and onboard production devices with production OTPs.

## Checklist

**Configuration**

- [ ] `ZATCA_ENVIRONMENT=production`
- [ ] `ZATCA_ENCRYPTION_KEY` set, backed up in a secrets manager (not only `.env`)
- [ ] `ZATCA_ARTIFACT_DISK` is private, backed up, shared between servers
- [ ] `QUEUE_CONNECTION` is not `sync`; Redis (or similar) for queue and cache
- [ ] TLS verification on (default); `ZATCA_CA_BUNDLE` set if needed
- [ ] Seller address and IDs correct (they are printed on every invoice)
- [ ] Correction policies (`zatca.correction.*`) confirmed with your tax advisor
- [ ] Retention period (`retention.years`) confirmed with your tax advisor

**Processes**

- [ ] Cron runs `php artisan schedule:run` every minute
- [ ] Queue workers run under Supervisor/Horizon with `--tries=1`
- [ ] Optional: `zatca:outbox:relay --daemon` under Supervisor
- [ ] Workers restart on deploy (`php artisan queue:restart`)

**Monitoring**

- [ ] Listeners for `InvoiceRejected`, `InvoiceSubmissionFailed`, `InvoiceReportingDeadlineAtRisk`,
      `InvoiceReportingDeadlineBreached`, `CertificateExpiring`
- [ ] Alert on failing `zatca:chain:verify`
- [ ] Someone owns the daily review of `REJECTED` / `FAILED` / `MANUAL_REVIEW` invoices

**Integration**

- [ ] B2B invoices are given to buyers only after `CLEARED`, as the cleared XML
- [ ] Receipts print the QR from the response
- [ ] Your clients retry with the same idempotency key
- [ ] HTTP API (if enabled) is behind authentication

**Database**

- [ ] Backups include all `zatca_*` tables
- [ ] Tables are InnoDB (MySQL/MariaDB)
