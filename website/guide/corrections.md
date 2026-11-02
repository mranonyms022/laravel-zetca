# Rejections, retries and corrections

When something goes wrong, there are exactly three ways forward. Which one applies depends on whether
ZATCA gave a **final answer** for the document.

| Situation | Operation | New UUID / counter? |
|---|---|---|
| No final answer yet (timeout, ZATCA down, retries exhausted, authentication problem) | **A. Retry** the identical document | No |
| ZATCA **rejected** the document, or the JSON was invalid | **B. Correct** and reprocess | Yes (new issuance) |
| ZATCA **accepted** the document, but it is wrong | **C. Credit or debit note** | New document, new record |

::: danger Never do these
- Change an accepted invoice. ZATCA has it; only a credit or debit note can change it.
- Resend a rejected document unchanged. ZATCA will reject the same bytes again.
- Delete invoices, issuances or attempts. Everything is kept for the audit trail.
:::

## Reading the rejection

```bash
php artisan zatca:invoice show 01M6E1Y8WXFJ0RK6YXMP4JN9WX
```

`show` prints the statuses, the number of unresolved errors and the last error code. ZATCA's full
messages (for example `BR-KSA-37 Saudi building numbers have exactly 4 digits`) are in `zatca_errors`;
every call to ZATCA, with its sanitised response, is in `zatca_submission_attempts`:

```sql
SELECT e.code, e.message, e.severity, e.created_at
FROM zatca_errors e JOIN zatca_invoices i ON i.id = e.invoice_id
WHERE i.public_id = '01M6E1Y8WXFJ0RK6YXMP4JN9WX' AND e.resolved_at IS NULL;
```

## A. Retry the submission

Sends the **same signed document** again: same UUID, counter, hash, signature and QR.

```php
use CodingTriplets\Zatca\Laravel\Facades\Zatca;

Zatca::invoices()->retry($invoiceId, actorId: auth()->id());              // queued
Zatca::invoices()->retry($invoiceId, actorId: auth()->id(), now: true);   // inside this call
```

```bash
php artisan zatca:invoice retry 01M6E1Y8WXFJ0RK6YXMP4JN9WX --actor=7
php artisan zatca:invoice retry 01M6E1Y8WXFJ0RK6YXMP4JN9WX --now
```

Allowed when the invoice is `QUEUED`, `RETRY_PENDING`, `FAILED`, `OUTCOME_UNKNOWN` or `MANUAL_REVIEW` and
the document has no final ZATCA result. Refused for accepted or rejected documents, and while a worker is
sending it.

## B. Correct and reprocess

Saves a corrected JSON as a **new version** (the original stays untouched) and issues it again:

```php
$corrected = $originalPayload;
$corrected['buyer']['address']['building_number'] = '1234';

$result = Zatca::invoices()->correct($invoiceId, $corrected, reason: 'Wrong buyer building number', actorId: auth()->id());
$result->toArray(); // new counter, uuid, qr
```

```bash
php artisan zatca:invoice correct 01M6E1Y8WXFJ0RK6YXMP4JN9WX --file=corrected.json --reason="Wrong buyer building number" --actor=7
```

What happens:

1. The corrected JSON is validated. If it is invalid, `InvoiceValidationException` is thrown and **nothing
   is saved**.
2. Version n+1 is stored with the reason, the author and the list of changed fields.
3. A new issuance is created: **new UUID, next counter of the device, PIH = hash of the device's last
   document**. The rejected document stays in the chain (ZATCA has seen it).
4. The old issuance is marked `superseded`, its errors are marked resolved.
5. It is submitted like any new document. Events: `InvoiceReissued`, and for B2C `CustomerCopyReissued`
   (with the new QR, so you can give the customer an updated receipt).

The invoice record (`invoice_id`) stays the same; both issuances appear in its history.

Correcting also works for invoices that never got a document (`VALIDATION_FAILED`): the first issuance is
then created.

### Reissue without changes

If the rejection was caused by something outside the JSON (for example a certificate problem that has
since been fixed), issue a new document from the same JSON:

```php
Zatca::invoices()->reissueUnchanged($invoiceId, reason: 'Certificate renewed', actorId: auth()->id());
```

```bash
php artisan zatca:invoice reissue 01M6E1Y8WXFJ0RK6YXMP4JN9WX --reason="Certificate renewed"
```

### Invoice number and issue date of a corrected document

| Workflow | Invoice number | Issue date | Config |
|---|---|---|---|
| B2C | Kept | Original issue date | `zatca.correction.b2c_keep_invoice_number`, `b2c_keep_issue_date` |
| B2B | Kept | Date of the reissue | `zatca.correction.b2b_keep_invoice_number`, `b2b_update_issue_date` |

If you set "keep invoice number" to `false`, the corrected payload must contain a new `invoice_number`.

::: warning Pending ZATCA confirmation
ZATCA's published guidelines do not state these rules explicitly. The defaults above are the package's
best reading and are configurable. If ZATCA or your tax advisor requires a credit note for rejected
invoices, set `zatca.correction.credit_note_for_rejected = true`; correcting and reissuing are then refused.
:::

### Approval (four-eyes)

With `zatca.correction.four_eyes = true`, a correction is saved but not issued. The invoice waits in
`MANUAL_REVIEW` until a **different** user approves it:

```php
Zatca::invoices()->approve($invoiceId, approverId: auth()->id());
```

```bash
php artisan zatca:invoice approve 01M6E1Y8WXFJ0RK6YXMP4JN9WX --actor=9
```

## C. Credit and debit notes

For accepted invoices. See [Credit and debit notes](./notes).

## Errors

All operations throw `CodingTriplets\Zatca\Laravel\Invoices\OperationNotAllowed` with a clear reason
when the operation does not fit the invoice's state, for example:

- "it was accepted by ZATCA; issue a credit or debit note instead"
- "its document has no final ZATCA result yet; retry the submission instead"
- "a submission is in progress"
