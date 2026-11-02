# Events

Namespace `CodingTriplets\Zatca\Core\Events`. All properties are public and read-only. Events fire after
the transaction commits and never contain secrets.

`invoiceId` is the internal numeric ID; `publicId` is the `invoice_id` (ULID) returned to clients.
Every invoice event also has `sourceType` and `sourceId`: the record of your own you linked with the
`source` option (`null` when not linked). See [Your own invoice tables](/guide/host-models).

## Invoices

| Event | Properties |
|---|---|
| `InvoiceIssued` | `invoiceId`, `publicId`, `issuanceId`, `workflow`, `counter`, `uuid` |
| `InvoiceAccepted` | `invoiceId`, `publicId`, `issuanceId`, `workflow`, `status`, `withWarnings` |
| `InvoiceRejected` | `invoiceId`, `publicId`, `issuanceId`, `workflow`, `status` |
| `InvoiceSubmissionFailed` | `invoiceId`, `publicId`, `issuanceId`, `workflow`, `reason` |
| `InvoiceReissued` | `invoiceId`, `publicId`, `issuanceId`, `previousIssuanceId`, `workflow`, `reason`, `counter`, `uuid`, `actorId` |
| `CustomerCopyReissued` | `invoiceId`, `publicId`, `issuanceId`, `qr` |
| `InvoiceReportingDeadlineAtRisk` | `invoiceId`, `publicId`, `issuanceId`, `deadlineAt`, `hoursLeft` |
| `InvoiceReportingDeadlineBreached` | `invoiceId`, `publicId`, `issuanceId`, `deadlineAt` |
| `ClearanceDeactivated` | `invoiceId`, `publicId`, `issuanceId` |
| `InvoiceCancelled` | `invoiceId`, `publicId`, `kind` (`credit_note`, `discarded`, `abandoned`), `reason`, `cancellationPublicId`, `actorId` |

## Devices and certificates

| Event | Properties |
|---|---|
| `DeviceKeyGenerated` | `deviceId`, `deviceKeyId`, `keyFingerprint` |
| `ComplianceCsidIssued` | `deviceId`, `certificateId`, `environment` |
| `DeviceOnboarded` | `deviceId`, `certificateId`, `environment`, `validUntil` |
| `DeviceOnboardingFailed` | `deviceId`, `onboardingId`, `step`, `reason` |
| `CertificateExpiring` | `deviceId`, `certificateId`, `daysLeft`, `validUntil` |
| `CertificateExpired` | `deviceId`, `certificateId` |
| `CertificateRenewed` | `deviceId`, `previousCertificateId`, `newCertificateId`, `validUntil` |
| `CertificateRenewalFailed` | `deviceId`, `renewalId`, `reason` |
