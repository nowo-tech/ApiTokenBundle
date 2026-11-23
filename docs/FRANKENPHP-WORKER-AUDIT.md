# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/api-token-bundle` (`symfony-bundle`) |
| Audited revision | S2 firewall + limits (unreleased, branch `feat/s2-firewall-limits`); S1 core audited 2026-11-12 |
| Audit date | 2026-11-23 (S2), 2026-11-12 (S1) |
| Method | Manual review of every file under `src/`; PHPStan `ruleset-classic` + `ruleset-worker-strict` + `ruleset-hardening`; Igor (`composer igor`); `WorkerRequestLoopTest` (one kernel, 2,000 requests, two owners alternating, memory drift < 1 MiB) |
| **Verdict** | ✅ **Viable** — every S1 service is stateless except the after-commit queue, which holds request-scoped work and drops it at every request / message boundary and on `kernel.reset` |

## Table of contents

- [Execution model assumed](#execution-model-assumed)
- [Summary](#summary)
- [Services reviewed](#services-reviewed)
- [Rules for the next slices](#rules-for-the-next-slices)
- [Re-audit triggers](#re-audit-triggers)

## Execution model assumed

FrankenPHP worker mode boots the kernel once and serves many requests with the same container. The bundle must work
in the strictest case: **kernel kept alive and NOT reset** (`FRANKENPHP_RESET_KERNEL=false`), with no services
resetter between requests.

## Summary

| Area | Status | Notes |
|------|--------|-------|
| Mutable state in shared services | ✅ | Only `AfterCommitQueue` (held after-commit work of an open host transaction, weak-keyed by connection) |
| `ResetInterface` / `kernel.reset` | ✅ | `AfterCommitQueue` implements it and is also cleared on `kernel.finish_request` (main request), `kernel.terminate`, `console.terminate`, `WorkerMessageHandledEvent` and `WorkerMessageFailedEvent`; `WorkerRequestLoopTest` covers each path |
| Cross-owner leakage | ✅ | `WorkerRequestLoopTest`: one kernel, 2,000 requests, two owners and four tokens alternating (valid, wrong checksum, expired, revoked mid-run, missing); each sees only its own principal and the refused ones none |
| Stale identity (host EntityManager) | ✅ | The default `DoctrineOwnerResolver` re-reads the user with a refresh query on every call and detaches it again when it loaded it, so an account disabled by another process or worker is refused on the next request and the host's identity map does not grow per owner (audit LW-M2); the worker test disables an owner by direct SQL mid-run. DDD resolvers must not answer from a cache or an identity map |
| Security token storage (S2) | ✅ | Without the services resetter Symfony keeps the last request's security token in `security.token_storage`, and a stateless firewall never replaces it on a request without an `Authorization` header (nor does a path outside the firewall). `ApiTokenTokenStorageResetListener` empties the storage unconditionally on `kernel.request` before any firewall (priority 512, main requests only, without reading it: a request whose `kernel.finish_request` never ran, because `framework.handle_all_throwables: false` rethrew an `\Error` or a host `finish_request` listener threw first, leaves nothing to the next one, audit ZAB-R-L1), and on `kernel.finish_request` (main requests only; HttpKernel dispatches it after a response and on the exception path) when the authenticator signed the request in (a request mark, so a token replaced afterwards by a 2FA listener or a stateless `switch_user` goes too, audit ZAB-S2-L2) or when the request went through a firewall that lists the authenticator (FirewallPass publishes the list). It never reads the storage first, so a host's stateful `lazy: true` firewall is never authenticated after the controller (audit ZAB-S2-L3), and it uses `security.untracked_token_storage` (no session usage marking). `WorkerRequestLoopTest::testTheFirewallNeverCarriesAUserOrPrincipalIntoTheNextRequest` runs 2,000 firewall requests (valid, wrong secret, no header, outside the firewall, revoked mid-run) and `testALazySharedFirewallWithAReplacedTokenNeverCarriesAUserIntoTheNextRequest` 500 more (a replaced token, another authenticator on the same firewall, a host lazy firewall); removing the listener makes the functional suite fail |
| Rate limits (S2) | ✅ | Counters in the host's cache pool (`rate_limit.cache_pool`); the `RateLimiterFactory` services hold configuration only and create a limiter per call; no lock, no file handle |
| Memory growth | ✅ | < 1 MiB drift after warm-up over 2,000 requests (direct and through the firewall) |
| Static properties / `static` locals | ✅ | None (the bundle's extension instance is the Symfony boot-time cache, `@igor-ignore` with justification) |
| Superglobals, `putenv`, `ini_set`, `setlocale`, timezone | ✅ | None |
| Doctrine | ✅ | `DbalApiTokenStore` writes through DBAL in a transaction it opens and closes, never flushes an EntityManager and never keeps a managed entity; the connection is resolved per call and a lost connection is retried once outside a transaction |
| Resources held open | ✅ | None |

## Services reviewed

| Class | State | Verdict |
|-------|-------|---------|
| `NowoApiTokenBundle` | Extension instance cached at boot | ✅ boot-time only |
| `DependencyInjection\NowoApiTokenExtension`, `Configuration`, `ConfigNodes`, `Compiler\RequireDoctrinePass` | Container build only | ✅ |
| `ApiTokenIssuer`, `ApiTokenRevoker`, `ApiTokenQuery`, `ApiTokenAuthenticator`, `TokenCodec`, `TokenHasher` | `final readonly`, injected collaborators and scalars only | ✅ (Igor's closure / clock notes are `@igor-ignore` with a reason: closures passed to one call, immutable dates) |
| `ModuleRegistry` | Built once from config and tagged providers, immutable | ✅ |
| `DbalApiTokenStore`, `ApiTokenMetadataListener`, `DoctrineOwnerResolver`, `UserCheckerOwnerStatus`, config defaults | `final readonly`, no memo; owner re-read from the database and status checked with the host's user checkers on every call | ✅ |
| `AfterCommitQueue`, `AfterCommitMiddleware`, `AfterCommitQueueResetListener` | Request-scoped held work, cleared at every boundary | ✅ |
| `IssueTokenCommand`, `PurgeExpiredTokensCommand` | Console only, no state between runs | ✅ |
| `ApiTokenFirewallAuthenticator`, `SymfonyApiTokenRateLimiter`, `ClientIpHasher`, `ReachFilterChain` (S2) | `final readonly`; the principal and the rate limit answer go on the request (and the request's security token), never on a service | ✅ (Igor's note on `registerFailedToken()` is `@igor-ignore`: the count goes to the cache pool) |
| `ApiTokenTokenStorageResetListener` (S2) | Stateless (the firewall context list is build-time configuration); empties the storage at the end of main requests the bundle signed in or that went through its firewalls | ✅ |
| `Compiler\FirewallPass` (S2) | Container build only | ✅ |

## Rules for the next slices

- No request, user, owner or tenant data in service properties, statics or process caches unless the bundle clears it
  at the request boundary (`kernel.request` / `kernel.terminate` / `kernel.finish_request`, and Messenger
  `WorkerMessageHandledEvent`); memos also implement `ResetInterface` with a test, never relied on alone.
- The bundle never flushes the host's EntityManager; writes use DBAL in an explicit transaction opened and closed in
  the call; a dropped connection is retried once.
- Rate-limit counters live in the cache pool, never in process memory.
- Every stateful path joins `WorkerRequestLoopTest` (N requests, two owners, flat memory).

## Re-audit triggers

Any new service, listener, memo, static, cache or Doctrine access; any change of the worker test.
