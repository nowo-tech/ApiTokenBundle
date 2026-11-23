# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- S2 firewall + limits (contract 221-A §6.3, §6.5, §6.7), registered with `enabled: true`:
  - `Infrastructure\Security\ApiTokenFirewallAuthenticator` for the host's **stateless** firewalls
    (`custom_authenticators`): the `Authorization` header only (`Bearer`, case-insensitive, or bare), a
    `SelfValidatingPassport` for the token's user (the firewall's user checker runs), the principal and the rate
    limit answer stamped on the request only after Symfony accepted the passport, and an entry point.
  - `ApiTokenContext::current($request)` / `ApiTokenContext::rateLimit($request)`; earlier `_nowo_api_token*`
    request attributes are removed before authenticating.
  - `firewall.on_failure`: `pass` (default, the request goes on with no user and each API answers its own refusal)
    or `unauthorized` (`401 {"err": "Invalid token", "ECODE": "TOKEN_000"}` with `WWW-Authenticate: Bearer`, and
    `429 {"err": "Too many requests", "ECODE": "TOKEN_001"}` with `Retry-After` for a used-up budget). Every failure
    kind answers identically.
  - Rate limits in `rate_limit.cache_pool` (`symfony/rate-limiter` fixed windows): `ApiTokenRateLimiterInterface`
    with `RateLimitState` / `RateLimitScope`; one bucket per token for every module (`per_token`), a write budget
    (`writes`; unsafe methods with `count_rest_writes: true`) and failed tokens per client IP (`failed_per_ip`,
    peeked before the presented value is parsed). The IP is keyed by an HMAC with `kernel.secret`.
  - `ApiTokenAuthFailedEvent` (`failure`, `lookupId` only for tokens that exist, `clientIpHash`, `path`) on every
    token failure; `ApiTokenEvents::AUTH_FAILED`.
  - `ReachFilterInterface` (autoconfigured, tag `nowo_api_token.reach_filter`) chained in the authenticator's step 8
    through `ReachFilterChain`, which intersects every answer with the reach it was given.
  - Build checks (`FirewallPass`): the cache pool must exist; a firewall that lists the authenticator must be
    stateless.
  - Worker mode: `ApiTokenTokenStorageResetListener` removes the bundle's own security token from the token storage at
    the end of each main request and before the next one (Symfony only clears it through the services resetter, so
    with the kernel kept alive and not reset a request without a token would otherwise run as the previous request's
    user). Worker test through the firewall (2,000 requests).
  - Firewall functional suite for the standard and the DDD host; host-API integration guide in
    [USAGE.md](USAGE.md#protecting-your-own-api).

- S3 account + operator pages (contract 221-A §8, §6.10, `REQ-AUTH-013`, `REQ-UI-002`), registered with
  `web_ui.enabled` / `admin_ui.enabled` and loaded by the route import `type: nowo_api_token`:
  - Account → Security → API tokens (`web_ui.path_prefix`, `IS_AUTHENTICATED_FULLY`): UiKit list (name, handle
    `nwt_k7m2…`, module levels, *agents may delete* badge, reach as *everything* or a count, created, last used,
    expiry with a warning chip within 7 days), *Revoke* (confirm dialog, FormKit CSRF form; another person's token
    id answers 404), *Revoke all* (re-auth + confirmation tick), *New token* (page or UiKit remote form dialog):
    re-auth step, name, one select per module offered by the policy, the module flags (MCP *destructive* off by
    default), reach (*everything* or the places of `ReachProviderInterface`, grouped), expiry from `expiry.choices`
    within the policy maximum.
  - The secret is shown once, in the answer of the POST that created it (a dialog opened on load, or the dialog
    fragment), printed once in the HTML; the form's CSRF token is renewed after the issue, so a reload issues nothing.
  - Re-auth (`ReauthGateInterface`, default `PasswordReauthGate` on the host's password hasher, `reauth.gate` for
    SSO hosts): a freshness mark in the session bound to the session id and the owner (`reauth.fresh_seconds`),
    `reauth.max_attempts` per 15 minutes and session.
  - `rate_limit.issue_per_owner` on the account page.
  - Framing headers on every page: `X-Frame-Options: DENY`, an extra `Content-Security-Policy: frame-ancestors 'none'`
    header (the host's policy is kept), `Cache-Control: no-store, private`, `Referrer-Policy: same-origin`.
  - Operator page (`admin_ui.path_prefix`): every token, keyset pages, filters by owner key and by lookup id / visible
    handle / whole token, *Revoke* (reason `operator`, the operator as actor). Access through
    `ApiTokenAccessCheckerInterface` (`admin_ui.security.access_roles`, `access_checker`, `allow_unauthenticated`
    for demos) and the per-token `ApiTokenAccessCheckEvent` (`ApiTokenEvents::ACCESS_CHECK`, deny wins), enforced by
    `ApiTokenAdminGuard` (REQ-UI-002).
  - `ApiTokenQueryInterface::findByLookupId()`; `ApiTokenRateLimiterInterface::consumeIssue()` / `consumeReauth()`
    (`RateLimitScope::Issue` / `Reauth`).
  - Build checks (`UiPass`): Twig, forms, router, SecurityBundle, CSRF and, for the password gate, a password hasher.
  - Translations ×7 for the pages (`NowoApiTokenBundle` domain) and the constraint messages (`validators` domain);
    [TEMPLATES.md](TEMPLATES.md); UiKit page contract test; page suite for the standard and the DDD host (own
    re-auth gate with its own form type); Flex recipe route file.

### Changed

- Requires `symfony/security-http`, `symfony/http-foundation` and `symfony/rate-limiter` (^7.4 || ^8.0) at runtime.
- Requires (S3) `nowo-tech/ui-kit-bundle` ^2.68, `nowo-tech/form-kit-bundle` ^2.24, `symfony/form`, `symfony/validator`,
  `symfony/security-csrf`, `symfony/password-hasher`, `symfony/routing`, `symfony/translation-contracts`, `twig/twig`,
  `twig/extra-bundle` and `twig/string-extra` ^3.12 (REQ-TWIG-004).
- `ApiTokenAuthenticator` takes an optional `ReachFilterChain` (last constructor argument).

- Demo: runs in UiKit's demo platform shell (`nowo-tech/ui-kit-bundle` ^2.60); the demo's own header is gone and the pages use the shell page headers and section cards. Demo only, no bundle change.
- Requires `nowo-tech/transaction-doctrine` ^1.8.1 (fixes GHSA-9wh3-m3m5-jffx and GHSA-65w2-9r4h-848g).

## [0.2.0] - 2026-11-18

### Added

- Concurrency (REQ-RUNTIME-003, [CONCURRENCY.md](CONCURRENCY.md)): a lock conflict (deadlock, lock wait timeout,
  serialization failure) in a token write is replayed up to `concurrency.attempts` times when the store opened the
  transaction, then thrown as `ApiTokenConflictException` with `CONFLICT_001` (nothing committed) or `CONFLICT_002`
  (an earlier batch of `revokeAll()` / the purge was committed, or a savepoint was lost). `ApiTokenConflictListener`
  answers it with 409 (`conflict_response.status`, or 503), `X-Conflict-Code` and, for `CONFLICT_001` only,
  `Retry-After`, using the request commit watermark of transaction-doctrine 1.6. The commands exit 1 with the code.
- Configuration `concurrency` (`attempts`, `lock_timeout_ms`: PostgreSQL `SET LOCAL lock_timeout` of a unit the store
  opens) and `conflict_response` (`status`, `retry_after`).
- Table `<prefix>api_token_owner_lock` (`ApiTokenOwnerLock`, attribute twin `ApiTokenOwnerLockRecord`) and
  `ApiTokenStoreInterface::lockOwner()`.
- Two-connection race tests (a second PHP process holds real row locks) and a MariaDB 11.4 leg in the required
  `Databases` job (now SQLite, MySQL 8.4, MariaDB 11.4, PostgreSQL 16).
- Committed WebP demo screenshot (`docs/images/demo/overview.webp`) captured by Playwright, `make demo-screenshots` and a README image (REQ-DEMO-013)

### Changed

- Requires `nowo-tech/transaction-doctrine` ^1.6 (and `nowo-tech/transaction-contract` ^1.0): the store's units run
  through `DoctrineTransactionRunner::forConnection()`, which repairs the nesting level after a failed nested unit.
- Demo shell rebuilt on UiKit's admin layout and default theme, no Bootstrap/CDN CSS; the demo requires `nowo-tech/ui-kit-bundle` (REQ-DEMO-014)
- Demo: Playwright smoke e2e (`make -C demo/symfony8 test-e2e`); screenshots wait for the S3 UI (REQ-DEMO-013)

- Demo `.gitignore` ignores `/.pnpm-store` (REQ-GITIGNORE-003)
- README: `## Requirements` moved right after `## Installation` (REQ-DOCS-019)

### Fixed

- The live-token cap (`max_per_owner`) could be exceeded by concurrent issues for one owner (count-then-insert): the
  issuer now takes the owner's lock row before it counts and checks the name. Contention only, no GHSA.
- A lock conflict while issuing or revoking surfaced as a 500; it is now a 409 with a conflict code.

[0.2.0]: https://github.com/nowo-tech/ApiTokenBundle/releases/tag/v0.2.0

## [0.1.0] - 2026-11-11

### Added

- S0 scaffold (contract 221-A, [CONTRACT.md](CONTRACT.md)): the bundle class `NowoApiTokenBundle`, the strict configuration
  tree `nowo_api_token` with every key of the contract (`enabled: false` by default; nothing is registered in S0) and the
  container parameters `nowo_api_token.enabled`, `nowo_api_token.api_prefix`, `nowo_api_token.table_prefix` and
  `nowo_api_token.config`.
- Quality gates: PHPUnit (100 % coverage gate), PHPStan level 8 with the FrankenPHP worker rulesets, PHP-CS-Fixer,
  Rector, Igor, CI matrix (PHP 8.2 to 8.5, Symfony 7.4 and 8.1), a SQLite / MySQL / PostgreSQL job, a worker test (one
  kernel kept alive, 2,000 requests) and the FrankenPHP demo smoke test.
- Docs set, Spec Kit baseline, Flex recipe, demo skeleton (Symfony 8.1, FrankenPHP worker mode, MySQL) with the
  dev stack (Web Profiler, Debug, Twig Inspector, Hot Reload over Mercure), `make test-with-db` on a MySQL 8.4 service
  of the root Compose file (profile `db`).
- S1 core (contract 221-A §4–§6.2, §6.6, §6.8, §10), registered only with `enabled: true`:
  - Public ports in `Nowo\ApiTokenBundle\Contract`: value objects (`OwnerRef`, `TokenId`, `LookupId`, `Grants`, `Reach`,
    `ModuleDefinition`, `ModuleRegistry`, `ApiTokenView`, `ApiTokenPrincipal`, `IssueRequest`, `IssuedToken`, …), the
    `AuthFailure` / `RevocationReason` enums, the issuer, revoker, query and authenticator interfaces and the host SPIs
    (owner resolver and status, reach provider, policy, settings provider, revocation listener, module provider).
  - Token format `<prefix><lookup id>_<secret><checksum>` (12-character base32 lookup id, 256-bit secret, CRC32 base62
    checksum), SHA-256 at rest or HMAC-SHA256 with a pepper (`hash.version: 2`).
  - Authenticator in the contract order: no database read for malformed values, a constant-time comparison on a miss,
    expiry, module switch and owner status on every call, grants narrowed on read, *last used* throttled to once a
    minute.
  - Table `<prefix>api_token`: plain domain class `Domain\ApiToken` mapped in XML (default) with an attribute twin
    (`doctrine.mapping: attribute`), UUIDv7 ids, table and index prefix, binary collations on MySQL / MariaDB.
  - `DbalApiTokenStore`: DBAL only, its own transaction or the host's open one, events and revocation listeners after
    the outermost commit, one reconnect on a lost connection, damaged rows skipped.
  - Default owner resolver (Doctrine, `owner.class`), which re-reads the user from the database on every call and
    detaches it again when it loaded it (fresh status in worker mode, no identity-map growth); default owner status on
    the host's user checkers: every firewall's `security.user_checker.<firewall>` chained, or `owner.user_checker`
    (service id or `none`); the container refuses to build without a real checker; a non-`UserInterface` user is
    inactive when a checker is used. DDD hosts plug their own resolver and status.
  - The issuer refuses an owner that does not exist or is inactive (`ApiTokenIssueRefusedException::OWNER`).
  - Token names unique per owner, case-insensitively, through the `name_key` column and the unique index
    `uniq_<prefix>api_token_owner_name`.
  - `IssuedToken::secret()` (the secret is private): `json_encode` redacts it, `serialize()` / `unserialize()` throw,
    `var_export`, `(array)`, `print_r` and VarDumper never show it.
  - Commands `nowo:api-token:issue` (prints the secret once) and `nowo:api-token:purge-expired` (keyset pages, deletes
    damaged expired rows, logs start / success / failure), registered from their `#[AsCommand]` attribute.
  - The `nowo_api_token.config` parameter redacts `hash.pepper`.
  - Events `ApiTokenIssuedEvent`, `ApiTokenRevokedEvent`, `ApiTokenUsedEvent` (ids and value objects only).
  - `Grants` keeps modules in key order, so a stored grant reads back identically on MySQL (which reorders JSON keys), PostgreSQL and SQLite.
  - Runtime dependencies: `doctrine/dbal` ^4, `doctrine/orm` ^3.3, `symfony/{clock,console,event-dispatcher,security-core,uid}`, `psr/{clock,event-dispatcher,log}`. DoctrineBundle is required at runtime once enabled.

[Unreleased]: https://github.com/nowo-tech/ApiTokenBundle/compare/v0.2.0...HEAD
[0.1.0]: https://github.com/nowo-tech/ApiTokenBundle/releases/tag/v0.1.0
