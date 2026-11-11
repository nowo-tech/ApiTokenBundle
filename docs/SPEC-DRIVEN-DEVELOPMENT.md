# Spec-driven development

This document is the single entry point for **what ApiTokenBundle guarantees**, **how it is proven** and **which
`REQ-*` anchors** the Makefiles and demos carry. There is no separate executable spec language: **PHPUnit** and
**PHPStan** are the normative proof alongside the prose.

## Table of contents

- [Layers kept in sync](#layers-kept-in-sync)
- [User stories](#user-stories)
- [Bundle functional scope](#bundle-functional-scope)
- [Validating the functional spec](#validating-the-functional-spec)
- [Requirement identifiers](#requirement-identifiers)
- [Contributor workflow](#contributor-workflow)
- [Relationship to Engram](#relationship-to-engram)
- [GitHub Spec Kit](#github-spec-kit)
- [See also](#see-also)

## Layers kept in sync

| Layer | Role | Where |
| --- | --- | --- |
| Design contract | Contract 221-A, owner-approved 2026-11-12 (every Q-O and Q-B as recommended): ports, persistence, security model, configuration, worker design, tests, slices | [CONTRACT.md](CONTRACT.md), [ROADMAP.md](ROADMAP.md) |
| GitHub Spec Kit baseline | Feature spec (user scenarios, `FR-*`, `SC-*`, entities, non-goals) and the 100 % code inventory of `src/` | [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md), [`specs/001-baseline/code-inventory.md`](../specs/001-baseline/code-inventory.md), manual in [SPEC-KIT.md](SPEC-KIT.md) |
| Product behaviour | What integrating applications may rely on | [USAGE.md](USAGE.md), [CONFIGURATION.md](CONFIGURATION.md), [INSTALLATION.md](INSTALLATION.md), [SECURITY.md](SECURITY.md) |
| Traceability anchors | Stable `REQ-*` ids in the Makefiles, CI and demo files, so PRs and issues can cite tooling behaviour | [Requirement identifiers](#requirement-identifiers) |
| Change management | Consumer-facing deltas | [CHANGELOG.md](CHANGELOG.md), [UPGRADING.md](UPGRADING.md), [RELEASE.md](RELEASE.md) |

Every slice updates the baseline spec, the code inventory and the integrator docs in the same pull request.

## User stories

| ID | Story | Scope | Integrator docs |
| --- | --- | --- | --- |
| US-S0-01 | **As an** integrator, **I want** to install the package and write the final configuration now **so that** later slices only switch features on. | Strict config tree, parameters | [CONFIGURATION.md](CONFIGURATION.md) |
| US-S0-02 | **As a** maintainer, **I want** every quality gate (tests, coverage 100 %, PHPStan + worker rules, Igor, CS, Rector, demo smoke) from the first PR **so that** no slice lowers the bar. | Makefile, CI | [CONTRIBUTING.md](CONTRIBUTING.md), [GITHUB_CI.md](GITHUB_CI.md) |
| US-S0-03 | **As a** platform maintainer, **I want** a worker-mode test (one kernel, many requests) from S0 **so that** each stateful slice extends it. | `WorkerRequestLoopTest` | [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md) |
| US-S1-01 | **As an** integrator, **I want** to issue a token for an existing, active person with per-module grants, a reach and an expiry **so that** an integration gets only what the person chose. | Issuer, registry, reach, policy | [USAGE.md](USAGE.md#issuing-checking-and-revoking-tokens-s1) |
| US-S1-02 | **As an** API owner, **I want** to check a presented token and get the owner, grants and reach, or a failure kind **so that** my API never sees a disabled, expired, revoked or unknown token. | Authenticator, owner status | [USAGE.md](USAGE.md#issuing-checking-and-revoking-tokens-s1), [SECURITY.md](SECURITY.md) |
| US-S1-03 | **As a** person, **I want** to revoke one or all of my tokens **so that** a leaked token stops working at once. | Revoker, events | [USAGE.md](USAGE.md#events-s1) |
| US-S1-04 | **As a** DDD host, **I want** to keep my user model, entity manager and transactions **so that** the bundle never touches my domain or flushes my EntityManager. | Ports, XML mapping, DBAL store | [USAGE.md](USAGE.md#using-it-from-a-ddd--hexagonal-application) |
| US-S1-05 | **As an** operator, **I want** to issue a token from the console and purge expired rows **so that** tests, break-glass access and housekeeping need no UI. | Commands | [USAGE.md](USAGE.md#console-commands-s1) |

## Bundle functional scope

**Goal:** personal API tokens for Symfony applications: a store, a token format and hash, the issue / revoke / query /
authenticate services, then (later slices) the firewall, rate limits, account and operator pages, module bridges and a
small public API.

**In scope (S1, 0.1.0):** the `Contract` ports and value objects; the token format, hash at rest and constant-time
verification; issue (owner must exist and be active), revoke, revoke all, operator revoke, purge; query with keyset
pages; the module registry; the owner resolver and status defaults (standard hosts) and SPIs (DDD hosts); the DBAL
store with after-commit events; the `issue` and `purge-expired` commands.

**Explicit non-goals (v1):** OAuth 2 (no authorization code, refresh tokens, client credentials or device flow);
organisation / project / machine tokens (Q-O7); HMAC request signing; deciding what a module allows (each API keeps
checking the person's permissions; a token only narrows them); a usage page (HttpLog's job).

**Not in the Packagist API:** `demo/` (illustrative only, excluded from the archive), `tests/`, the `@internal`
classes (`TokenCodec`, `TokenHasher`, `AfterCommitQueue`, `ModuleRegistryFactory`, …).

## Validating the functional spec

```bash
make test-coverage-100   # PHPUnit (Unit, Integration, Database on SQLite) + 100 % statements / elements of src/
make test-with-db        # Database suite (standard + DDD hosts) on MySQL 8.4 (REQ-TEST-005)
make phpstan igor        # PHPStan level 8 + FrankenPHP worker rulesets, Igor worker-state audit
make qa                  # cs-check + test
make release-check       # the whole pre-release chain, demo included
```

Coverage floor: **100 %** of `src/` (statements and elements; CI gate). Any behaviour change ships with tests under
`tests/` (unit for value objects and services, functional for both host kinds, worker loop for state).

## Requirement identifiers

| `REQ-*` | Where | Meaning |
| --- | --- | --- |
| REQ-MAKE-001 / 002 | `Makefile` (`help`, `ensure-up`, `release-check`) | Standard targets, sequential release chain |
| REQ-MAKE-003 | `demo/symfony8/Makefile` | Demo targets incl. `test-coverage`, `verify` |
| REQ-MAKE-007 | `Makefile` `down-dev` | Stop without removing volumes |
| REQ-MAKE-008 | `Makefile`, `demo/Makefile`, `demo/symfony8/Makefile` (soft includes) | `update-deps`, `update-deps-all` |
| REQ-MAKE-004 / REQ-I18N-002 | `Makefile` `validate-translations` | YAML syntax + key parity of the 7 locales |
| REQ-TEST-003 / 006 | `Makefile` `test-coverage-100`, `coverage-check` | 100 % gate |
| REQ-TEST-005 | `Makefile` `test-with-db`, `test-coverage-with-db`; `docker-compose.yml` service `database` (profile `db`) | Database suite on MySQL |
| REQ-TEST-011 | `Makefile` / `demo/Makefile` `demo-smoke`, `.github/workflows/demo-smoke.yml` | Demo boots, HTTP 200 |
| REQ-CS-001 | `Makefile` `validate-phpdoc` | PHPDoc on every class and public method |
| REQ-CS-008 | `Makefile` `igor` | Igor worker-state audit |
| REQ-TWIG-004 / 005 | `Makefile` `check-twig-extra`, `check-no-raw-html-form` | Twig extras, no raw form tags |
| REQ-REL-003 | `Makefile` `check-open-prs` | No open PRs left at release |
| REQ-GIT-001 | `Makefile` `setup-hooks`, CI job "Git history" | No agent co-author trailers |
| REQ-DEMO-001 / 002 / 010 / 012 | `demo/symfony8/composer.json`, `config/bundles.php`, `docker/frankenphp/Caddyfile*`, `docker/entrypoint.sh` | Dev stack, FrankenPHP + Mercure + hot reload, `FRANKENPHP_MODE`, Symfony 8.1 |

When scripted behaviour changes, update the matching anchor comment and this table in the same pull request.

## Contributor workflow

1. **Clarify** the behaviour against [CONTRACT.md](CONTRACT.md) and the baseline spec (add or adjust `US-*`, `FR-*`,
   `SC-*` first).
2. **Implement with tests**: a failing test first where practical; keep the 100 % gate, PHPStan, Igor and the worker
   test green.
3. **Anchor scripts**: tooling changes keep or add their `REQ-*` comment.
4. **Ship integrator docs**: USAGE / CONFIGURATION / SECURITY / CHANGELOG / UPGRADING whenever behaviour or
   configuration changes; refresh `specs/001-baseline/code-inventory.md` when `src/` files change.

## Relationship to Engram

[ENGRAM.md](ENGRAM.md) covers the organisation-wide plane (Engram MCP memory, `.cursor/mcp.json`, checklist hygiene).
This document covers the product plane (behaviour and traceability). Neither replaces the other; Engram points here
for local `REQ-*` traceability.

## GitHub Spec Kit

The repository is initialised for GitHub Spec Kit (`.specify/`, Cursor Agent skills in `.cursor/skills/speckit-*`).
The full manual (install, initialise, navigate `.specify/` vs `specs/`, skills) is [SPEC-KIT.md](SPEC-KIT.md). The
baseline lives in [`specs/001-baseline/`](../specs/001-baseline/): `spec.md` (scenarios, requirements, success
criteria) and `code-inventory.md` (every file of `src/` mapped to `FR-*`). Keep the inventory aligned with `src/` in
every pull request that adds, moves or removes a source file.

## See also

- [GitHub Spec Kit](SPEC-KIT.md)
- [Usage](USAGE.md) · [Configuration](CONFIGURATION.md) · [Installation](INSTALLATION.md)
- [Contributing](CONTRIBUTING.md) · [Release](RELEASE.md) · [Roadmap](ROADMAP.md)
- [Demo with FrankenPHP](DEMO-FRANKENPHP.md) · [FrankenPHP worker audit](FRANKENPHP-WORKER-AUDIT.md)
