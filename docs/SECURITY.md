# Security

## Table of contents

- [Status and scope](#status-and-scope)
- [Attack surface](#attack-surface)
- [Threat model](#threat-model)
- [Mitigations](#mitigations)
- [Secrets and cryptography](#secrets-and-cryptography)
- [Logging](#logging)
- [Permissions and exposure](#permissions-and-exposure)
- [Limits and denial of service](#limits-and-denial-of-service)
- [Dependencies and updates](#dependencies-and-updates)
- [Release security checklist (12.4.1)](#release-security-checklist-1241)
- [Audits](#audits)
- [Reporting](#reporting)

## Status and scope

S1 core (0.1.0) and S2 firewall + limits (0.3.0 after its REQ-SEC-004 audit): the store, the token format, the hash at
rest, the issuer, revoker, query and authenticator services, two console commands, the stateless firewall
authenticator and the rate limits. The bundle registers **no route**: a token is accepted only on the host's own
firewalls that list the authenticator, and nothing is registered while `enabled` is false.

**Covers:** how tokens are generated, stored, verified, listed and revoked, and how the owner's account status gates
every use. **Does not cover:** what a module allows (each API keeps checking the person's own permissions; a token only
narrows them), the host's sign-in, sessions or user provider, and transport security (HTTPS is the host's job).

## Attack surface

| Input | Where | Handling |
| --- | --- | --- |
| Presented token (string) | `ApiTokenAuthenticatorInterface::authenticate()`; over HTTP the `Authorization` header only (`Bearer` or bare), never a query string, cookie or body | Length ≤ 128 and an anchored regex before anything else; checksum checked before any I/O; never logged, never in an exception message (`#[\SensitiveParameter]`) |
| Issue request | `ApiTokenIssuerInterface::issue()` (S3 form, CLI, host code) | Name (1–80 chars, no control characters), channel, grants (module registry, policy, module switches), reach (subset of the owner's choices, ≤ `reach.max_entries`), expiry (1 … min(config, policy, 365) days), owner must exist and be active |
| Value objects | `OwnerRef`, `TokenId`, `LookupId`, `Grants`, `Reach` | Validated on construction; messages state the pattern, never the value |
| Configuration | `nowo_api_token` tree | Strict (unknown keys refused), validated values (prefix, table prefix, module keys, limits) |
| CLI arguments | `nowo:api-token:issue` (owner id, name, grants, reach, days), `nowo:api-token:purge-expired` | Same validation as the issuer; the secret is generated, never read from argv |
| Database rows | `<prefix>api_token` | Re-validated on read: a damaged row is skipped (never authenticates), stored grants are narrowed by the registry, a damaged reach reaches nothing |
| Client IP | `Request::getClientIp()` (trusted proxies are the host's configuration) | Keyed by HMAC-SHA256 with `kernel.secret` in the limiter cache and in events; never stored or logged in clear |
| Host SPIs | owner resolver / status, reach provider, reach filters, policy, settings, revocation listeners | Host code; the bundle fails closed on null or inactive answers, and a failing revocation listener never undoes a revocation |

## Threat model

From contract §6.11. Each threat names the control that answers it and the slice that ships it.

| Threat | Control | Slice |
| --- | --- | --- |
| Token leak in logs, CI, serialisation or exceptions | Secret only in `IssuedToken` (private, `secret()` accessor; `json_encode` redacted, `serialize()` refused, `var_export` / `(array)` / VarDumper / `print_r` never show it), `#[\SensitiveParameter]`, no secret in flash / session / log / event / cache; exception messages never include the presented value | S1, S3 |
| Database read leak | SHA-256 of the full token at rest (256-bit secret), optional HMAC pepper | S1 |
| Lookup-id enumeration | Every failure kind (unknown, wrong secret, expired, revoked, owner inactive, module off, malformed, missing) answers the client identically (`pass`: no user; `unauthorized`: one fixed `401 TOKEN_000` body); the failure kind goes to the event only, and the lookup id only for tokens that exist | S1, S2 |
| Timing | `hash_equals()` always, a dummy hash on a miss, never a SQL comparison of the hash | S1 |
| Brute force | Failed tokens per client IP (20 / 15 min), peeked before parsing (a blocked IP costs no database read and is refused even with a valid token), 2^256 secrets | S2 |
| Budget doubling across APIs | One bucket per token consumed once per request by the firewall, for every module; bridges read the request's answer instead of consuming again | S2, S4 |
| Session fixation through a token | The container refuses to build when a firewall that lists the authenticator is not stateless, so a token never becomes a web session | S2 |
| Forged request stamp | `ApiTokenContext` is written only by the authenticator, after Symfony accepted the passport; any earlier `_nowo_api_token*` attribute is removed in `supports()` and on failure | S2 |
| Reach widening by a host filter | Each `ReachFilterInterface` answer is intersected with the reach it was given | S2 |
| Clickjacking of the create page | `X-Frame-Options: DENY`, `frame-ancestors 'none'` appended to the host CSP | S3 |
| CSRF on revoke | FormKit CSRF forms on every session POST; the Bearer API is exempt (stateless) | S3 |
| Privilege growth | Grants re-validated against the registry on read; unknown levels dropped, never widened; every API re-checks the person's permissions | S1, S4 |
| Collation (audit LQ) | `owner_id` in `utf8mb4_bin`, PHP `OwnerRef::equals()` re-check on every access / erasure query | S1 |
| Worker-mode bleed between two tokens | Principal only on the request attribute; no memo of tokens, owners or reach; the token storage emptied at the end of every main request the authenticator signed in (also when a 2FA listener or a stateless `switch_user` replaced its token) or that went through a firewall listing it, without reading it first (a host's lazy firewall is never authenticated late); N-request tests direct and through the firewall | S1, S2 |
| Foreign credentials on a shared firewall | `supports()` only claims values with the token prefix: `Basic` or JWT values of another authenticator are never counted against the IP budget, never raise `ApiTokenAuthFailedEvent` and never get `TOKEN_000` | S2 |
| Revocation and in-flight requests | A request authenticated just before a revocation commits still completes; see [CONCURRENCY.md](CONCURRENCY.md#residual-risks) | S2 |
| Account disabled, locked or erased | Owner status checked on every request with the host's own user checker (every firewall's checker, or `owner.user_checker`), the user re-read from the database each time (no identity-map staleness in worker mode), no cache | S1 |
| Dangling token for a missing owner | Issue refuses an owner that does not exist or is inactive (`ApiTokenIssueRefusedException::OWNER`) | S1 |
| Injection | Every SQL value is a bound parameter; the table name comes from a validated prefix; column lists are constants | S1 |
| Deserialisation | `IssuedToken` refuses `serialize()` / `unserialize()`; stored JSON is decoded with a depth limit and validated | S1 |

## Mitigations

- **Format and verification:** `<prefix><12-char lookup id>_<43-char secret><6-char CRC32>`, 256-bit secrets from
  `random_bytes`, fixed verification order with no database read for malformed values, a dummy constant-time
  comparison on a miss, no SQL condition on the hash, expiry and the module switch checked before the owner.
- **Owner status:** the default `UserCheckerOwnerStatus` runs the host's user checker (every firewall's
  `security.user_checker.<firewall>`, chained, or the `owner.user_checker` service); with a checker, a user object that
  is not a Symfony `UserInterface` is inactive. The container refuses to build when no real checker and no
  `owner.status` is configured, unless `owner.user_checker: none` says that only the resolver decides.
- **Fresh owner:** the default Doctrine resolver re-reads the user on every call (refresh query) and detaches it again
  when it loaded it itself, so a deactivation by another process or worker takes effect on the next request.
- **Grants and reach** only narrow: unknown modules, levels and flags are dropped on read; a damaged reach reaches
  nothing.
- **Names:** unique per owner, case-insensitively, through the `name_key` column and the unique index
  `uniq_<prefix>api_token_owner_name`. The live-token cap (`max_per_owner`) is a check inside the issue transaction:
  concurrent issues may exceed it by a few, and the S3 per-owner issue limiter bounds that.
- **Revocation deletes the row**; listeners and events run after the commit; the next request with that token is
  `Unknown`.
- **Expiry is mandatory** (≤ 365 days, Q-O5).

## Secrets and cryptography

- **Hash at rest:** `hash_version 1` = SHA-256 of the full token (256-bit secrets make a slow hash useless);
  `hash_version 2` = HMAC-SHA256 with `hash.pepper`, which must come from an environment variable
  (`'%env(NOWO_API_TOKEN_PEPPER)%'`), never from a committed file. The pepper is not copied into the
  `nowo_api_token.config` container parameter.
- **Mass invalidation (no rotation in 0.x):** changing `hash.pepper`, going from `hash.version: 2` back to 1, or
  changing `token_prefix` invalidates **every existing token** at once (their stored hash or their prefix no longer
  matches). Plan such a change as a forced re-issue and tell your users first. A `previous_peppers` rotation with
  rehash-on-use may come in a later minor.
- **Shown once:** the secret exists only in the `IssuedToken` returned by `issue()`; it is never stored, logged, put
  in an event, the session, a flash message or a cache. Do not pass `IssuedToken` to `dump()`, the profiler or a
  logger context: it redacts itself, but the token string you read from `secret()` does not.
- **Checksum:** CRC32 in base62, a typo check and a secret-scanning marker, not a security control.
- No secret, key or credential is committed: CI and demo databases are passwordless on private networks, the demo
  `APP_SECRET` is generated at first start, `.env` files are git-ignored (GitGuardian-safe repository).

## Logging

Logged (PSR-3, only when a `logger` service exists): token ids, lookup ids, failure kinds, exception classes, counts.
The purge command logs its start (cutoff), its success (rows deleted, damaged rows deleted) and its failure (exception
class). A damaged row is logged with the validation message, which names the pattern, never the value.

Never logged: the secret, the presented value, the hash, the pepper, reach entries, raw IP addresses.

`ApiTokenAuthFailedEvent` carries the failure kind, the lookup id only when the token exists (`WrongSecret`,
`Expired`, `OwnerInactive`), a 16-character HMAC of the client IP and the route name (never the query string).

## Permissions and exposure

- S1 exposes **no HTTP endpoint and no controller**. The services are public container ids for host code; the two
  console commands need shell access to the host (break-glass by design).
- S2 adds the firewall authenticator (stateless firewalls only, refused at build otherwise) and no route; S3 the account page
  (`IS_AUTHENTICATED_FULLY` plus re-authentication) and the operator page (`ROLE_ADMIN` by default); S5 the versioned
  `<prefix>/tokens/v1/*` API, Bearer only.

## Limits and denial of service

- Presented values longer than 128 characters are refused before parsing; reach ≤ `reach.max_entries` (≤ 1,000);
  stored JSON decoded with depth 8.
- One indexed read per authentication; *last used* written at most once a minute per token, outside host
  transactions.
- `max_per_owner` live tokens (default 20); the purge runs in keyset batches with a time budget
  (`messenger.max_seconds`).
- Rate limits (S2), fixed windows in `rate_limit.cache_pool`: 100 requests per token per minute for every module,
  30 writes per token per minute (unsafe methods with `count_rest_writes`, McpKit tools in S4), 20 failed tokens per
  client IP per 15 minutes. No lock: exactly concurrent requests can over-admit by at most the number of workers. The
  pool must be shared by every worker and server; behind a proxy, `framework.trusted_proxies` must be set, or every
  client shares the proxy's IP budget (one client could then block the others). S3 adds the issue-per-owner limit.

## Dependencies and updates

- Runtime dependencies are Symfony components, Doctrine DBAL 4 / ORM 3 and PSR interfaces only.
- `composer audit --locked` runs in every CI matrix job and fails on advisories; Dependabot opens update PRs; a
  security fix in a dependency is released as a patch of the current 0.x / 1.x minor.
- `make update-deps` refreshes the bundle and demo locks; `make release-check` must stay green.

## Release security checklist (12.4.1)

Confirm each item before every tag (note it in the release PR):

| # | Item | How |
| --- | --- | --- |
| 1 | `docs/SECURITY.md` and `.github/SECURITY.md` current (supported versions list the new minor) | Review |
| 2 | `.env`, `.env.local`, `.env.*.local`, `.env.dev` git-ignored (root and demo) | `.gitignore`, `demo/symfony8/.gitignore` |
| 3 | No secrets in the repository | GitGuardian check green on the release PR |
| 4 | Safe config / recipe defaults | `enabled: false`; Flex recipe copies only `enabled: false` |
| 5 | Input validation and output escaping | Value objects, issuer checks, strict config; no Twig output in S1 (S3: Twig auto-escaping, FormKit) |
| 6 | Dependencies audited | `composer audit --locked` green in CI |
| 7 | No secret in logs, events or exceptions | Tests serialise every event and grep the logs; `#[\SensitiveParameter]` on secret parameters |
| 8 | Cryptography | SHA-256 / HMAC-SHA256, `random_bytes`, `hash_equals`; pepper only from the environment |
| 9 | Permissions and exposure | No route in S1; later slices per [Permissions and exposure](#permissions-and-exposure) |
| 10 | Limits / DoS | [Limits and denial of service](#limits-and-denial-of-service) |
| 11 | AI security audit (REQ-SEC-004) passed for the slice | Grade recorded in the audit appendix (`BUNDLES_SECURITY_ANALYSIS.md`); blocking findings fixed |

## Audits

- S1 to S5 are authentication slices: each needs an **immediate REQ-SEC-004 audit** before a tag that contains it.
- The S1 audit (appendix LW, 2026-11-11, Medium on `639eff8`) found the owner-status checker selection (LW-M1), the
  stale identity-map user in worker mode (LW-M2), `IssuedToken` serialisation (LW-L1), issue without an owner check
  (LW-L2) and the undocumented mass invalidation (LW-L3); all are fixed or documented on the S1 branch and go back for
  a delta re-audit before 0.1.0.
- Every tag also needs the `bundle-req-audit` at 100 % of the applicable REQs (new-bundle rule).

## Reporting

See [.github/SECURITY.md](../.github/SECURITY.md): report privately to hectorfranco@nowo.tech.
