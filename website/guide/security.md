# Security

## Secrets

| Secret | How it is protected |
|---|---|
| Device private keys | Envelope encryption (AES-256-GCM) under `ZATCA_ENCRYPTION_KEY` (or your KMS). Never exported |
| CSID secrets | Same envelope encryption |
| Portal OTP | Read as hidden input, sent to ZATCA, **never stored or logged** |
| Signed XML | Private disk only; SHA-256 checked on every read; optional encryption (`ZATCA_ENCRYPT_ARTIFACTS`) |

### Key rotation

1. Generate a new key and set it as `ZATCA_ENCRYPTION_KEY`.
2. Put the old key in `ZATCA_PREVIOUS_ENCRYPTION_KEYS` (comma-separated) so existing secrets stay readable.

### KMS

Set `zatca.security.kms` to a class implementing
`CodingTriplets\Zatca\Core\Contracts\Security\KeyManagementService` to keep the master key in AWS KMS,
Vault or similar.

## Transport

- TLS certificate verification is always on. It cannot be disabled in production (the package refuses to
  boot). Use `ZATCA_CA_BUNDLE` if PHP lacks a CA bundle.
- XML parsing has external entities and network access disabled (XXE protection).

## Storage

- The artifact disk must be **private**. Never point it at `public`; signed invoices must not be
  reachable by URL.

## Logs and audit

- Logs never contain keys, secrets, OTPs or full invoice XML. Stored submission attempts contain the
  hash and UUID instead of the XML, and ZATCA's response with sensitive values removed.
- Device registration, onboarding and renewal are written to an append-only, hash-chained audit log;
  `php artisan zatca:audit:verify` detects tampering. Every invoice status change, with actor and
  reason, is written to `zatca_processing_logs`.

## Access control

- The HTTP API has no authentication of its own: add your middleware (see [HTTP API](./http-api)).
- Operations accept an `actorId` that is recorded in the history. Pass the authenticated user.
- Four-eyes approval for corrections: `zatca.correction.four_eyes = true`.
- The M7 dashboard will use Laravel's Gate (or your own `PermissionResolver`) for role-based access.

## Data integrity

- The original JSON is never modified; corrections create new versions.
- Signed XML is never modified after signing.
- Database unique keys make duplicate counters and chain forks impossible.
- Nothing is deleted (except processed outbox rows after `retention.outbox_processed_days`).

## Reporting a vulnerability

Please do not open a public issue. Use GitHub's private vulnerability reporting on the repository
(Security → Report a vulnerability).
