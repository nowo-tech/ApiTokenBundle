# Feature Specification: ApiTokenBundle baseline

**Feature Branch**: `001-baseline`
**Created**: 2026-11-12
**Status**: Baseline (S0 scaffold)
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

## Requirements

| ID | Requirement |
| --- | --- |
| FR-BUNDLE-001 | The bundle class returns the `nowo_api_token` extension. |
| FR-CONFIG-001 | Strict tree: every key of contract §7 with its default; invalid values name the path. |
| FR-CONFIG-002 | Container parameters published for `security.yaml` (one-value API prefix rule). |
| FR-WORKER-001 | One kernel kept alive serves N requests with no bleed between owners and flat memory. |
| FR-DB-001 | Identifier columns compare exactly on SQLite, MySQL (`utf8mb4_bin`) and PostgreSQL. |
| FR-I18N-001 | Seven translation catalogues with the same keys (en, es, it, fr, pt, de, nl). |

## Success criteria

- `make release-check` green; CI green (matrix, coverage 100 %, PHPStan, CS, databases, demo smoke).
