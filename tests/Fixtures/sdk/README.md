# ZATCA SDK sample invoices

Unmodified sample documents from the ZATCA E-Invoicing SDK 238-R3.3.3 (`Data/Samples`), published by the
Zakat, Tax and Customs Authority under the GNU LGPL v3. These files are test data only. They are not part of
the package's distributed code (see `.gitattributes` export-ignore for `tests/`).

The test suite uses them to confirm that the package reproduces ZATCA's hashing, signing and QR formats
byte-for-byte (`tests/Unit/Invoice/SdkGoldenTest.php`).

| File | SDK path |
|---|---|
| Simplified_Invoice.xml | Samples/Simplified/Invoice/Simplified_Invoice.xml |
| Simplified_Credit_Note.xml | Samples/Simplified/Credit/Simplified_Credit_Note.xml |
| Standard_Invoice.xml | Samples/Standard/Invoice/Standard_Invoice.xml |
| Standard_Debit_Note.xml | Samples/Standard/Debit/Standard_Debit_Note.xml |
| Exempt_Tax_Invoice.xml | Samples/Standard/Invoice/Exempt Tax Invoice.xml |
