# Feature Specification: ApiTokenBundle baseline

**Feature Branch**: `001-baseline`
**Created**: 2026-11-12
**Status**: Baseline (S0 scaffold + S1 core)
**Input**: Contract 221-A ([`docs/CONTRACT.md`](../../docs/CONTRACT.md)), owner-approved 2026-11-12 with every Q-O
answered as recommended.

Related: [`docs/SPEC-DRIVEN-DEVELOPMENT.md`](../../docs/SPEC-DRIVEN-DEVELOPMENT.md),
[`docs/CONFIGURATION.md`](../../docs/CONFIGURATION.md), [`docs/ROADMAP.md`](../../docs/ROADMAP.md),
[`code-inventory.md`](code-inventory.md).

## Summary

S0 guarantees: the bundle registers in a standard Symfony host with zero configuration; the configuration root
`nowo_api_token` is strict (unknown keys and invalid values are refused with the path in the message) and already holds
every key of the contract; `enabled` defaults to false; S0 registers no service, route, listener or table, whatever the
configuration says; the container parameters `nowo_api_token.enabled`, `nowo_api_token.api_prefix`, `nowo_api_token.table_prefix`
and `nowo_api_token.config` exist.

S1 guarantees (only with `enabled: true`): tokens are issued, checked, listed and revoked through the public ports for
standard and DDD hosts alike; the secret is shown once and only its hash is stored; verification follows the contract
order with no database read for malformed values and the same constant-time work on a miss; grants never widen;
expiry is mandatory; writes go through DBAL only and their listeners and events run after the outermost commit; no
state survives a request in worker mode.

## Requirements

| ID | Requirement |
| --- | --- |
| FR-BUNDLE-001 | The bundle class returns the `nowo_api_token` extension. |
| FR-CONFIG-001 | Strict tree: every key of contract §7 with its default; invalid values name the path. |
| FR-CONFIG-002 | Container parameters published for `security.yaml` (one-value API prefix rule). |
| FR-WORKER-001 | One kernel kept alive serves N requests with no bleed between owners and flat memory. |
| FR-DB-001 | Identifier columns compare exactly on SQLite, MySQL (`utf8mb4_bin`) and PostgreSQL. |
| FR-I18N-001 | Seven translation catalogues with the same keys (en, es, it, fr, pt, de, nl). |
| FR-PORT-001 | Public ports (`Contract`) are interfaces, enums and `final readonly` value objects with no Doctrine, Symfony or HTTP type; identifiers are validated on construction. |
| FR-FORMAT-001 | Token = prefix + 12-char base32 lookup id + `_` + 43-char base64url secret (256 bits) + optional 6-char CRC32 base62 checksum; a wrong checksum, length, prefix or charset is malformed without I/O. |
| FR-HASH-001 | Only SHA-256 (version 1) or HMAC-SHA256 with a pepper (version 2) of the full token is stored; comparison with `hash_equals()` only, never in SQL. |
| FR-ISSUE-001 | Issue validates the module switch, policy, name, channel, grants, reach and expiry (≤ 365 days), enforces the live-token cap and unique names per owner in one transaction, retries a lookup-id collision, returns the secret once. |
| FR-AUTH-001 | Authenticate in the order: format, checksum, lookup, hash, expiry, module switch, owner resolved and active (every call), grants narrowed; *last used* at most once a minute, outside open transactions. |
| FR-REVOKE-001 | Revoke (owner-checked exactly), revoke all, operator revoke and purge delete the rows; revocation listeners and the event run after commit; a failing listener never undoes it. |
| FR-QUERY-001 | Owner lists newest first and capped; operator keyset pages; every owner-scoped result re-checked with `===`. |
| FR-GRANT-001 | Module registry from config and tagged providers; stored grants re-validated on read, unknown modules or levels dropped. |
| FR-EVENT-001 | Issued, revoked and used events carry ids and value objects only and are dispatched after the outermost commit (dropped on rollback). |
| FR-STORE-001 | DBAL-only store on the configured entity manager's (or DBAL) connection; joins a host transaction; reconnects once outside a transaction; damaged rows skipped and logged. |
| FR-DB-002 | XML mapping by default and an attribute twin with the same schema; table and index names follow `doctrine.table_prefix`; MySQL binary collations on identifiers. |
| FR-HOST-001 | Standard hosts: Doctrine owner resolver on `owner.class`, `security.user_checker` status. DDD hosts: own resolver, status, entity manager and table prefix, same functional suite. |
| FR-CLI-001 | `nowo:api-token:issue` prints the secret once on stdout; `nowo:api-token:purge-expired` deletes expired rows in batches within a time budget. |

## Success criteria

- `make release-check` green; CI green (matrix, coverage 100 %, PHPStan, CS, databases, demo smoke).
