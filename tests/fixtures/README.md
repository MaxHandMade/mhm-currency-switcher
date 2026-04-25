# Test RSA Key Pair

**Purpose:** Test-only RSA-2048 key pair for `RsaSigner` and `FeatureTokenIssuer` tests. Allows real `openssl_sign` / `openssl_verify` calls in PHPUnit suite — no mocks, deterministic.

**WARNING:** These keys are committed to git. They are **NEVER** to be used as production signing keys. Production keys are generated independently and deployed only to wpalemi.com wp-config (`MHM_LICENSE_SERVER_RSA_PRIVATE_KEY_PEM`).

**Mirror across 3 repos** (byte-identical, verify with `sha256sum`):

- `mhm-license-server/tests/fixtures/test-rsa-{private,public}.pem`
- `mhm-rentiva/tests/fixtures/test-rsa-{private,public}.pem`
- `mhm-currency-switcher/tests/fixtures/test-rsa-{private,public}.pem`

## Reference Checksums (Phase A — generated 2026-04-25)

```
f32d2e6961a6d9b50db686d6bb94c5df0d804414d21b80d795faa4d8101eb858  test-rsa-private.pem
5f6a68bc75c4aa708fe5158d4638a011328999e4245e22f18f3e574bcdcaf9f5  test-rsa-public.pem
```

Phase B and Phase C repos MUST match these checksums after copy.

## Regenerate (only if compromised — extremely unlikely for test keys)

```bash
openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out test-rsa-private.pem
openssl pkey -in test-rsa-private.pem -pubout -out test-rsa-public.pem
```

If regenerated, all 3 repos must be updated together and the checksum reference above refreshed.
