# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/nowo-tech/ApiTokenBundle/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/nowo-tech/ApiTokenBundle/releases/tag/v0.1.0
