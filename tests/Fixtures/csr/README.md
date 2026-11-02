Test-only material (never used outside the test suite):

- `test-secp256k1-key.pem`: throwaway secp256k1 key.
- `openssl-reference.cnf` / `openssl-reference.csr`: CSR produced by `openssl req -new -sha256 -key test-secp256k1-key.pem -config openssl-reference.cnf`
  (the configuration used by OpenSSL-based ZATCA integrations). The package's CSR generator must produce a byte-identical to-be-signed part.
- `test-certificate.pem`: self-signed certificate for that key, used to fake ZATCA's binarySecurityToken in mocked responses.
