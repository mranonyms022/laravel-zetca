# Requirements

| Item | Requirement |
|---|---|
| Laravel | 9.52+, 10.x, 11.x, 12.x or 13.x |
| PHP | 8.1 or newer, as supported by your Laravel version (L9: 8.1–8.2, L10: 8.1–8.3, L11/L12: 8.2–8.4, L13: 8.3–8.4) |
| PHP extensions | `openssl`, `dom`, `libxml`, `mbstring`, `json`, and the PDO driver of your database |
| Database | MySQL 8.0+, MariaDB 10.6+ or PostgreSQL 13+ (SQL Server: not yet, see [Compatibility](/reference/compatibility)) |
| Queue | Any Laravel queue driver except `sync` in production (Redis, database, SQS, …) |
| Cache | A shared cache store (Redis recommended) when you run more than one server or worker |
| Storage | A **private** filesystem disk for signed XML. Use a shared disk (S3 or similar) with several app servers |
| Scheduler | Laravel's scheduler (`schedule:run` every minute via cron) |
| Outbound HTTPS | Access to `gw-fatoora.zatca.gov.sa` on port 443 |

::: info SQLite
SQLite works for local development and tests only. It allows one writer at a time.
:::

::: warning MySQL / MariaDB storage engine
All package tables are created with **InnoDB**, even if your server's default is MyISAM. MyISAM has no
transactions or row locks, which the invoice chain depends on. Do not convert the tables.
:::

## ZATCA prerequisites

- A VAT registration with ZATCA and access to the **Fatoora portal** (to generate OTPs for device onboarding).
- Your company's **national address** (building number: 4 digits, postal code: 5 digits), the
  commercial registration number (CRN) or another seller ID.
- For testing: nothing. The developer portal (sandbox) is open and uses the OTP `123345`.
