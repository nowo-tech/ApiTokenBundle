# Feature Specification: ApiTokenBundle baseline

**Feature Branch**: `001-baseline`
**Created**: 2026-11-12
**Status**: Baseline (S0 scaffold + S1 core, audit LW fixes)
**Input**: Contract 221-A ([`docs/CONTRACT.md`](../../docs/CONTRACT.md)), owner-approved 2026-11-12 with every Q-O
answered as recommended; S1 security audit LW (2026-11-11).

Related: [`docs/SPEC-DRIVEN-DEVELOPMENT.md`](../../docs/SPEC-DRIVEN-DEVELOPMENT.md),
[`docs/SPEC-KIT.md`](../../docs/SPEC-KIT.md), [`docs/CONFIGURATION.md`](../../docs/CONFIGURATION.md),
[`docs/USAGE.md`](../../docs/USAGE.md), [`docs/ROADMAP.md`](../../docs/ROADMAP.md), [`code-inventory.md`](code-inventory.md).

## Summary

**S0** guarantees: the bundle registers in a standard Symfony host with zero configuration; the configuration root
`nowo_api_token` is strict (unknown keys and invalid values are refused with the path in the message) and already holds
every key of the contract; `enabled` defaults to false and then nothing is registered (no service, route, listener or
table); the container parameters `nowo_api_token.enabled`, `nowo_api_token.api_prefix`, `nowo_api_token.table_prefix`
and `nowo_api_token.config` exist.

**S1** guarantees (only with `enabled: true`): tokens are issued, checked, listed and revoked through the public ports
(`Nowo\ApiTokenBundle\Contract\*`) for standard and DDD hosts alike; the secret is shown once, through
`IssuedToken::secret()` only, and only its hash is stored; verification follows the contract order with no database
read for malformed values and the same constant-time work on a miss; the owner must exist and be active, checked with
the host's own user checker and fresh data on every call; grants never widen; expiry is mandatory; writes go through
DBAL only and their listeners and events run after the outermost commit; no state survives a request in worker mode.

## User scenarios

### US-01 — Install and configure ahead of the slices (S0)

- **Given** a Symfony 7.4 / 8.x application, **when** the bundle is installed with no configuration, **then** the
  container builds, `nowo_api_token.enabled` is false and no bundle service, route or table exists.
- **Given** an unknown key or an invalid value under `nowo_api_token`, **when** the container builds, **then** it fails
  with a message that names the path and the allowed values.

### US-02 — Issue a token for a person (S1)

- **Given** an active owner and a valid request (name, grants known to the registry, a reach within the owner's
  choices, an expiry ≤ min(config, policy, 365) days), **when** `issue()` runs, **then** one row is stored with the
  hash only, `ApiTokenIssuedEvent` is dispatched after the commit and the result holds the secret once.
- **Given** an owner the resolver does not find, or an inactive owner, **when** `issue()` runs, **then** it throws
  `ApiTokenIssueRefusedException` with reason `owner` and nothing is stored.
- **Given** a second token with the same name in another case for the same owner, **when** `issue()` runs, **then** it
  is refused (`duplicate_name`), also under concurrency (database unique index).

### US-03 — Authenticate a presented token (S1)

- **Given** a malformed value (length, prefix, charset, checksum), **when** `authenticate()` runs, **then** it answers
  `Malformed` without any database query.
- **Given** an unknown lookup id or a wrong secret, **when** `authenticate()` runs, **then** both answers come after a
  `hash_equals()` comparison and no SQL ever compares the hash.
- **Given** a valid token whose owner was disabled by another process (direct SQL update), **when** the next request
  authenticates in the same kept-alive kernel, **then** it answers `OwnerInactive`.
- **Given** a host whose firewall sets its own user checker, **when** that checker refuses the owner, **then** the token
  is refused (`OwnerInactive`).
- **Given** a valid token for an active owner, **when** `authenticate()` runs, **then** it returns the principal with
  the grants narrowed by the registry and the module switches, and writes *last used* at most once a minute.

### US-04 — Revoke tokens (S1)

- **Given** a token of owner A, **when** owner B (or `usera` for `UserA`) asks to revoke it, **then** nothing happens and
  `revoke()` answers false.
- **Given** a revocation, **when** it commits, **then** the row is gone, each revocation listener runs, then
  `ApiTokenRevokedEvent`; a failing listener is logged and does not undo it; the next authentication answers `Unknown`.

### US-05 — Keep the host's domain intact (S1, DDD hosts)

- **Given** a host with its own entity manager, `auto_mapping: false`, its own owner resolver and status and another
  table prefix, **when** the functional suite runs, **then** it passes exactly as for a standard host.
- **Given** a host transaction open on the same connection, **when** the bundle issues or revokes, **then** it joins the
  transaction and holds its events until the outermost commit (dropped on rollback); it never flushes an EntityManager.

### US-06 — Operate from the console (S1)

- **Given** an active owner, **when** `nowo:api-token:issue` runs, **then** the secret is printed once on stdout and the
  summary on stderr.
- **Given** expired rows older than `expiry.purge_after_days`, including damaged ones, **when**
  `nowo:api-token:purge-expired` runs, **then** they are deleted in keyset batches within the time budget, live tokens
  are never touched, and the run logs its start, success or failure.

### US-07 — Never leak the secret (S1)

- **Given** an `IssuedToken`, **when** it goes through `json_encode`, `serialize`, `var_export`, an `(array)` cast,
  `print_r` or Symfony VarDumper, **then** the secret never appears (`serialize()` throws, the others redact).

## Functional requirements

### Bundle and configuration

| ID | Requirement |
| --- | --- |
| FR-BUNDLE-001 | The bundle class returns the `nowo_api_token` extension. |
| FR-CONFIG-001 | Strict tree: every key of contract §7 with its default; invalid values name the path. |
| FR-CONFIG-002 | Container parameters published for `security.yaml` (one-value API prefix rule); `nowo_api_token.config` redacts `hash.pepper`. |

### Ports and value objects

| ID | Requirement |
| --- | --- |
| FR-PORT-001 | Public ports (`Contract`) are interfaces, enums and `final readonly` value objects with no Doctrine, Symfony or HTTP type; identifiers are validated on construction. |
| FR-SECRET-001 | `IssuedToken` keeps the secret private (`secret()`); JSON redacts it, serialisation is refused, `var_export`, `(array)`, `print_r` and VarDumper never show it; the internal generated token behaves the same. |

### Token format and hash

| ID | Requirement |
| --- | --- |
| FR-FORMAT-001 | Token = prefix + 12-char base32 lookup id + `_` + 43-char base64url secret (256 bits) + optional 6-char CRC32 base62 checksum; a wrong checksum, length, prefix or charset is malformed without I/O. |
| FR-HASH-001 | Only SHA-256 (version 1) or HMAC-SHA256 with a pepper (version 2) of the full token is stored; comparison with `hash_equals()` only, never in SQL. A new pepper, v2 → v1 or a new prefix invalidates every token (documented). |

### Services

| ID | Requirement |
| --- | --- |
| FR-ISSUE-001 | Issue validates the module switch, policy, owner (exists and active), name, channel, grants, reach and expiry (≤ 365 days), enforces the live-token cap and unique names per owner (case-insensitive, unique index) in one transaction, retries a lookup-id collision, returns the secret once. |
| FR-AUTH-001 | Authenticate in the order: format, checksum, lookup, hash, expiry, module switch, owner resolved and active (every call), grants narrowed; *last used* at most once a minute, outside open transactions. |
| FR-REVOKE-001 | Revoke (owner-checked exactly), revoke all, operator revoke and purge delete the rows; revocation listeners and the event run after commit; a failing listener never undoes it. |
| FR-QUERY-001 | Owner lists newest first and capped; operator keyset pages; every owner-scoped result re-checked with `===`. |
| FR-GRANT-001 | Module registry from config and tagged providers; stored grants re-validated on read, unknown modules or levels dropped; canonical module-key order. |
| FR-EVENT-001 | Issued, revoked and used events carry ids and value objects only and are dispatched after the outermost commit (dropped on rollback). |

### Owner (host integration)

| ID | Requirement |
| --- | --- |
| FR-HOST-001 | Standard hosts: Doctrine owner resolver on `owner.class`. DDD hosts: own resolver, status, entity manager and table prefix, same functional suite. |
| FR-OWNER-001 | Default status runs the host's user checkers (every firewall's checker chained, or `owner.user_checker`, or none with `none`), refuses to build without a real checker, and treats a non-`UserInterface` user as inactive when a checker is used. The default resolver re-reads the user on every call and detaches what it loaded (fresh status in worker mode, no identity-map growth). |

### Persistence

| ID | Requirement |
| --- | --- |
| FR-STORE-001 | DBAL-only store on the configured entity manager's (or DBAL) connection; joins a host transaction; reconnects once outside a transaction; damaged rows skipped and logged. |
| FR-DB-001 | Identifier columns compare exactly on SQLite, MySQL (`utf8mb4_bin` / `ascii_bin`) and PostgreSQL. |
| FR-DB-002 | XML mapping by default (`src/Resources/config/doctrine-mapping/`) and an attribute twin with the same schema; table and index names follow `doctrine.table_prefix`; MySQL binary collations on identifiers. |

### CLI

| ID | Requirement |
| --- | --- |
| FR-CLI-001 | `nowo:api-token:issue` prints the secret once on stdout; `nowo:api-token:purge-expired` deletes expired rows (damaged ones included) in keyset batches within a time budget and logs start, success and failure; both are registered from `#[AsCommand]`. |

### Runtime and i18n

| ID | Requirement |
| --- | --- |
| FR-WORKER-001 | One kernel kept alive (no reset) serves N requests with no bleed between owners, flat memory, and a deactivation by SQL refused on the next request. |
| FR-I18N-001 | Seven translation catalogues with the same keys (en, es, it, fr, pt, de, nl); host override procedure documented. |

## Key entities

| Entity | Description |
| --- | --- |
| `ApiToken` (table `<prefix>api_token`) | One personal token: UUIDv7 id, public lookup id, secret hash + version, owner (`OwnerRef` type + id), name (+ `name_key`), grants, reach, created / expires / last used dates, channel, legacy hash. Bundle-owned, referenced by id |
| `OwnerRef` | The person, as type + id; never a relation to the host's user class |
| `Grants` | Module → level, plus ticked flags per module; validated against the `ModuleRegistry` |
| `Reach` | *Everything* (with exceptions) or a limited list of tenant / resource entries |
| `ApiTokenPrincipal` | Per-request result of authentication: token id and name, owner, host user object, narrowed grants, reach, expiry |
| `ApiTokenView` / `IssuedToken` | What lists, events and callers see (never the hash); the freshly issued token with its secret, once |

## Success criteria

| ID | Criterion |
| --- | --- |
| SC-001 | `make release-check` green; CI green on the PR head (PHP 8.2–8.5 × Symfony 7.4 / 8.1, coverage, PHPStan, CS, Rector, Igor, databases SQLite / MySQL / PostgreSQL, demo smoke, GitGuardian). |
| SC-002 | 100 % statement and element coverage of `src/` (`make test-coverage-100`). |
| SC-003 | The functional suite passes for both host kinds (`StandardHostTest`, `DddHostTest`) on SQLite, MySQL 8.4 and PostgreSQL 17. |
| SC-004 | The worker test serves 2,000 requests in one kernel with no cross-owner data, ≤ 1 MiB memory drift, and refuses an owner deactivated by SQL on the next request. |
| SC-005 | [`code-inventory.md`](code-inventory.md) maps **every** file of `src/` (**75** files at this baseline; the count is re-stated there on each slice) to its `FR-*` IDs, with no `FR-SRC-001` placeholder. |
| SC-006 | The S1 REQ-SEC-004 audit (and its delta re-audit) passes before the 0.1.0 tag. |

## Non-goals

- OAuth 2 flows, refresh tokens, client credentials, device flow (TimeTrack keeps its own).
- Organisation / project / machine tokens (Q-O7) and HMAC request signing.
- Deciding what a module allows: every API re-checks the person's permissions; a token only narrows them.
- In S1: HTTP firewall, rate limits, pages, bridges and the public API (S2–S5).

## Assumptions

- The host stores its users in an entity reachable by the default resolver, or provides its own resolver and status.
- Hosts that use account states configure them on a firewall user checker (or set `owner.user_checker`).
- Hosts run `doctrine:migrations:diff` (no migration classes ship) and keep HTTPS and trusted proxies in their own
  configuration.
- PHP 8.2–8.5, Symfony 7.4 / 8.0 / 8.1, Doctrine DBAL 4 and ORM 3.3+.

## Validation commands

```bash
make test-coverage-100      # PHPUnit + 100 % gate
make test-with-db           # Database suite on MySQL 8.4 (root compose, profile db)
make phpstan igor cs-check rector-dry validate-phpdoc validate-translations
make release-check          # everything, demo included
```
