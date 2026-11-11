# Spec-driven development

This repository follows the Nowo spec-driven workflow:

1. **Design contract** — [CONTRACT.md](CONTRACT.md) (contract 221-A, owner-approved 2026-11-12 with every Q-O answered
   as recommended). It fixes the public ports, persistence, security model, configuration, worker-mode design, tests
   and slices.
2. **Product behaviour** — what **ApiTokenBundle** guarantees to applications that integrate it (see [`USAGE.md`](USAGE.md),
   [`CONFIGURATION.md`](CONFIGURATION.md), [`INSTALLATION.md`](INSTALLATION.md)). **PHPUnit** and **PHPStan** enforce
   it in CI.
3. **Baseline** — [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md) and the 100 % code inventory
   [`specs/001-baseline/code-inventory.md`](../specs/001-baseline/code-inventory.md). Every slice updates both.
4. **Repository REQs** — `BUNDLES_FULL_SPECS_DETAILS.md` (Makefile, CI, demo, docs, security). The bundle reaches 100 %
   of the applicable REQs before 1.0.0 (`bundle-req-audit`), plus the REQ-SEC-004 audit.

## Table of contents

- [User stories (S0)](#user-stories-s0)
- [Slices](#slices)

## User stories (S0)

| ID | Story |
| --- | --- |
| US-S0-01 | **As an** integrator, **I want** to install the package and write the final configuration now **so that** later slices only switch features on. |
| US-S0-02 | **As a** maintainer, **I want** every quality gate (tests, coverage 100 %, PHPStan + worker rules, Igor, CS, Rector, demo smoke) from the first PR **so that** no slice lowers the bar. |
| US-S0-03 | **As a** platform maintainer, **I want** a worker-mode test (one kernel, many requests) from S0 **so that** each stateful slice extends it. |

## Slices

See [ROADMAP.md](ROADMAP.md).
