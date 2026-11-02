# ZATCA reference documents

Pinned sources the implementation is checked against. Update this file (with date and version)
whenever a newer edition is adopted; tests that encode a rule cite the rule ID.

Official index: <https://zatca.gov.sa/en/E-Invoicing/SystemsDevelopers/Pages/E-Invoice-specifications.aspx>
and <https://zatca.gov.sa/en/E-Invoicing/Introduction/Guidelines/Pages/default.aspx>

| Document | Version used | Used for | Status |
|---|---|---|---|
| E-Invoicing Detailed Guidelines | v1.0 (local copy; [online](https://zatca.gov.sa/en/E-Invoicing/Introduction/Guidelines/Documents/E-Invoicing_Detailed__Guideline.pdf)) | Prohibited functions (no modification/deletion, credit note to cancel, invalid documents stay in chain, single sequence per unit, no counter reset, no key export), 24-hour reporting | Read 2026-11-02. **Check online for a newer edition** |
| Security Features Implementation Standards | v1.1, 2022-06-24 (local). Online edition 2023-05-19 exists | CSR fields (Table 1), EGS serial, VAT-group OU, TSCZ flags, key requirements | Read 2026-11-02. **Diff against 2023-05-19 edition in M3** |
| XML Implementation Standard | 2022-06-24 vF (local). Online edition 2023-05-19 exists | UBL structure, KSA rules, hashing, QR | Partially read. **Use 2023-05-19 edition in M4** |
| Data Dictionary | 2023-05-19 (online only) | Field definitions | To download in M4 |
| E-Invoicing Detailed Technical Guideline | online | Technical requirements | To read in M3/M4 |
| Developer Portal Manual / Fatoora Portal User Manual | online | Onboarding, OTP, API flow | To read in M3 |
| **ZATCA E-Invoicing SDK** | **238-R3.3.3** (local: `D:\nirbhay\packages\zetca docs\zatca-einvoicing-sdk-238-R3.3.3`) | Golden-file checks (hash, signature, QR, validation); XSDs; schematrons (`20210819_ZATCA_E-invoice_Validation_Rules.xsl`, `CEN-EN16931-UBL.xsl`); 20+ official sample invoices (standard/simplified, invoice/credit/debit, error cases) | Available. Needs Java (not installed locally; available in GitHub Actions) |

## Non-official references

| Source | Use |
|---|---|
| `zatca-xml-js` (Repzo, MIT, local copy) | Cross-check only. Known issue #8: missing VAT category breaks TaxSubtotal (BR-S-01); handled by our VAT category rule (ARCHITECTURE 9.1) |
| ZATCA developer forum threads | Framing open questions only (ARCHITECTURE 3.1) |
