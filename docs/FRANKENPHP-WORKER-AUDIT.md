# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/api-token-bundle` (`symfony-bundle`) |
| Audited revision | S0 scaffold (unreleased, `main`) |
| Audit date | 2026-11-12 |
| Method | Manual review of every file under `src/`; PHPStan `ruleset-classic` + `ruleset-worker-strict` + `ruleset-hardening`; Igor (`composer igor`); `WorkerRequestLoopTest` (one kernel, 2,000 requests, two owners alternating, memory drift < 1 MiB) |
| **Verdict** | ✅ **Viable** — S0 registers no service; the bundle class and extension hold boot-time state only |

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
| Mutable state in shared services | ✅ | No service in S0 |
| `ResetInterface` / `kernel.reset` | n/a | No memo in S0 |
| Cross-owner leakage | ✅ | `WorkerRequestLoopTest`: each of 2,000 requests sees only its own owner header |
| Memory growth | ✅ | < 1 MiB drift after warm-up over 2,000 requests |
| Static properties / `static` locals | ✅ | None (the bundle's extension instance is the Symfony boot-time cache, `@igor-ignore` with justification) |
| Superglobals, `putenv`, `ini_set`, `setlocale`, timezone | ✅ | None |
| Doctrine | n/a | No table in S0; later slices write through DBAL in their own transactions and never keep managed entities |
| Resources held open | ✅ | None |

## Services reviewed

| Class | State | Verdict |
|-------|-------|---------|
| `NowoApiTokenBundle` | Extension instance cached at boot | ✅ boot-time only |
| `DependencyInjection\NowoApiTokenExtension`, `Configuration`, `ConfigNodes` | Container build only | ✅ |

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
