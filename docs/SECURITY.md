# Security

## Table of contents

- [Status](#status)
- [Threat model](#threat-model)
- [Controls by slice](#controls-by-slice)
- [Audits](#audits)
- [Reporting](#reporting)

## Status

S0 scaffold: no runtime code, no route, no table. Nothing is reachable whatever the configuration.

## Threat model

From contract §6.11. Each threat names the control that answers it and the slice that ships it.

| Threat | Control | Slice |
| --- | --- | --- |
| Token leak in logs, CI or exceptions | Secret only in `IssuedToken`, `#[\SensitiveParameter]`, no secret in flash / session / log / event / cache; exception messages never include the presented value | S1, S3 |
| Database read leak | SHA-256 of the full token at rest (256-bit secret), optional HMAC pepper | S1 |
| Lookup-id enumeration | `Unknown` and `WrongSecret` answer the client identically | S1, S2 |
| Timing | `hash_equals()` always, a dummy hash on a miss, never a SQL comparison of the hash | S1 |
| Brute force | Failed tokens per client IP (20 / 15 min), 2^256 secrets | S2 |
| Clickjacking of the create page | `X-Frame-Options: DENY`, `frame-ancestors 'none'` appended to the host CSP | S3 |
| CSRF on revoke | FormKit CSRF forms on every session POST; the Bearer API is exempt (stateless) | S3 |
| Privilege growth | Grants re-validated against the registry on read; unknown levels dropped, never widened; every API re-checks the person's permissions | S1, S4 |
| Collation (audit LQ) | `owner_id` in `utf8mb4_bin`, PHP `OwnerRef::equals()` re-check on every access / erasure query | S1 |
| Worker-mode bleed between two tokens | Principal only on the request attribute; no memo of tokens, owners or reach; N-request test | S1, S2 |
| Account disabled or erased | Owner status checked on every request, no cache | S1 |

## Controls by slice

- **S0:** strict configuration (unknown keys refused), `enabled: false`, no service registered; GitGuardian-safe
  repository (no credential-looking strings, passwordless CI databases).
- **S1–S5:** see the table above and the contract.

## Audits

- S1 to S5 are authentication slices: each needs an **immediate REQ-SEC-004 audit** before a tag that contains it.
- 1.0.0 also needs the `bundle-req-audit` at 100 % of the applicable REQs.

## Reporting

See [.github/SECURITY.md](../.github/SECURITY.md): report privately to hectorfranco@nowo.tech.
