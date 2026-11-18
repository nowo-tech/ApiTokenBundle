# Upgrading

## Table of contents

- [Unreleased](#unreleased)
- [To 0.1.0](#to-010)

## Unreleased

- **New table** `<prefix>api_token_owner_lock` (default `nowo_api_token_owner_lock`): run `doctrine:migrations:diff`
  and compare with the reference DDL in [INSTALLATION.md](INSTALLATION.md#reference-ddl-doctrinetable_prefix-nowo_).
  Issuing fails until the table exists. DDD hosts that register the XML directory get it automatically.
- **New dependency** `nowo-tech/transaction-doctrine` ^1.6 (with `nowo-tech/transaction-contract` ^1.0). The bundle
  passes it a DBAL connection only (`forConnection()`), never the host's EntityManager, so the host-EM
  `LogicException` on unflushed edits (`forHostEntityManager()`) does not apply.
- **Custom stores** (`ApiTokenStoreInterface` implementations) must add `lockOwner(OwnerRef $owner): void`, taken
  inside `transactional()` before the issuer's count (see `DbalApiTokenStore`).
- **Lock conflicts now answer 409** with `X-Conflict-Code: CONFLICT_001|CONFLICT_002` (and `Retry-After` for
  `CONFLICT_001`) instead of a 500, when they reach the kernel from the bundle's services. Set
  `conflict_response.status: 503` to keep a 503. Catch `ApiTokenConflictException` where you call the services
  from commands or handlers ([CONCURRENCY.md](CONCURRENCY.md)).
- `nowo:api-token:issue` and `nowo:api-token:purge-expired` exit 1 with the code on a lock conflict.

## To 0.1.0

First release (S0 scaffold, S1 core).

No release yet. The first tag follows [ROADMAP.md](ROADMAP.md). Every schema change in 1.x is additive and will be
listed here with its DDL.

S1 adds the table `<prefix>api_token` (default `nowo_api_token`) once the bundle is enabled: run
`doctrine:migrations:diff` and compare with the reference DDL in [INSTALLATION.md](INSTALLATION.md#reference-ddl-doctrinetable_prefix-nowo_).
Enabling the bundle now needs `owner.class` or `owner.resolver`, and DoctrineBundle.

Owner status: with the default status, the container refuses to build unless one of your firewalls has a user checker
other than Symfony's stock one, or you set `owner.user_checker` (a checker service id, or `none` to let the resolver
alone decide), or `owner.status` ([CONFIGURATION.md](CONFIGURATION.md#owner-status)).

`IssuedToken::$secret` is private: read it with `IssuedToken::secret()`.

**Changes that invalidate every token** (plan them as a forced re-issue): a new `hash.pepper`, going from
`hash.version: 2` back to 1, or a new `token_prefix` ([CONFIGURATION.md](CONFIGURATION.md#changes-that-invalidate-every-token)).
