# Roadmap

Built slice by slice from [CONTRACT.md](CONTRACT.md) (contract 221-A). Every slice is a pull request on
`nowo-tech/ApiTokenBundle`. S1 to S5 are **authentication slices**: each needs an **immediate REQ-SEC-004 audit**
before any tag that contains it (batch-audit rule 2026-11-07). One 0.x tag per slice after each audit (0.2.0 went to the concurrency release, see Road to 1.0.0); Beacon
adopts only 1.0.0 in production (Q-O6).

## Table of contents

- [Slices](#slices)
- [Road to 1.0.0](#road-to-100)
- [Owner decisions (2026-11-12)](#owner-decisions-2026-11-12)
- [Open questions for Beacon](#open-questions-for-beacon)

## Slices

| Slice | Version | Content | Audit | Status (2026-11-23) |
| --- | --- | --- | --- | --- |
| **S0** scaffold | `main`, no tag | Composer, Docker, Makefile + release-check, CI matrix, PHPStan + worker rulesets, Igor, Rector, CS, coverage gate, Spec Kit baseline, docs set, `.github`, release / sync workflows, Flex recipe, demo skeleton (FrankenPHP worker, MySQL), threat model, strict config tree `enabled: false`. **Then the owner registers the package on Packagist** | Batched (no runtime code) | Released (on `main`, packaged in v0.1.0) |
| **S1** core | 0.1.0 | Domain + XML mapping (+ attribute twin, Q-O11) + ID mode + table prefix, `DbalApiTokenStore`, format / hash / checksum, issuer, revoker, query, authenticator service, module registry, `Grants`, `Reach`, `OwnerRef` and the other value objects, owner resolver / status defaults, events after commit, purge command, CLI `nowo:api-token:issue` | **Immediate** | Released (v0.1.0, 2026-11-11, audit LW) |
| **S2** firewall + limits | 0.3.0 | `ApiTokenFirewallAuthenticator`, request stamp, `on_failure`, stateless check, rate limiters, failed-IP limiter, `ReachFilterInterface` chain, host-API docs | **Immediate** | PR #12 (`feat/s2-firewall-limits`), CI green, audit pending |
| **S3** account + operator UI | 0.4.0 | Account page, *New token* dialog, re-auth gate + framing headers, revoke / revoke all, operator page, translations ×7, templates doc | **Immediate** | Not started |
| **S4** bridges | 0.5.0 | TaskBoard, Wiki, McpKit bridges, HttpLog resolver + usage reader, UserKit eraser + export + sessions listener, AuditKit recorder, AuthKit re-auth adapter, AppTokenSource adapter | **Immediate** | Not started |
| **S5** public API + 1.0 | 0.6.0 → **1.0.0** | `<prefix>/tokens/v1/self` GET / DELETE, optional revocation endpoint, OpenAPI, legacy Beacon v1 path + `legacy-report`, `ApiTokenExpiringEvent` + command, `PurgeExpiredApiTokensMessage` + handler + schedule (the purge command enqueues by default, `--sync` for direct runs), docs, `bundle-req-audit` 100 % | **Immediate**, then 1.0.0 | Not started |
| Beacon | after 1.0.0 | Migration (contract §9), `security.yaml`, delete ≈ 1,600 lines | Beacon's own | Waiting for 1.0.0 (Beacon adopts after S3 and 1.0.0, Q-O6) |

TimeTrack bridge (Q-O8) and organisation / project tokens (Q-O7) are 1.x minors at the earliest.

## Road to 1.0.0

State on 2026-11-23: tags `v0.1.0` (S1 core) and `v0.2.0` (2026-11-18, **concurrency release, not slice S2**:
REQ-RUNTIME-003 conflict codes, owner lock row, MariaDB leg, `docs/CONCURRENCY.md`). `main` has two unreleased,
non-runtime changes (transaction-doctrine `^1.8.1`, demo in UiKit's platform shell). No open pull request.

Because `0.2.0` was used by the concurrency release, the slice-to-version column above is a plan, not a promise: S2 to S5
take the next free `0.x` tag (`0.3.0` and up), one per slice after its audit, and S5 ends in `1.0.0`.

What is left:

- **Slices S2 to S5** (S2 in PR #12, the rest not started): firewall authenticator and limits, account and operator UI, bridges, public API
  (`<prefix>/tokens/v1`), expiring event, purge message and schedule, OpenAPI. S2 to S5 are authentication slices: each
  needs an **immediate** REQ-SEC-004 audit before the tag that contains it (batch-audit rule 2026-11-07).
- **REQ gaps:** none open for what is built (latest regrade 2026-11-18b: 111 ok / 1 warn / 0 fail; the warn was
  REQ-RUNTIME-003 and is closed by 0.2.0, checklist row at v0.2.0: 112 ok, 0 gaps). The REQs deferred to S2 to S5 (65 in
  the 0.1.0 audit) must be satisfied before 1.0.0, and `bundle-req-audit` must reach 100 % of the applicable REQs on the
  final slice.
- **REQ-SEC-004:** appendix LW covers 0.1.0 (S1, delta LW Pass-Low) and appendix UC covers 0.2.0 (concurrency; one
  documented Low residual risk AT-1, REPEATABLE READ cap count). Nothing covers S2 to S5 yet.
- **Owner decisions:** none open. Q-O1 to Q-O12 are answered (2026-11-12); Beacon answered Q-B1 to Q-B8 as recommended
  (Beacon D-20261111-04), so the "Open questions for Beacon" below are closed. Beacon adopts only after S3 plus 1.0.0;
  its migration needs a path that keeps current `bt_` tokens valid, `mcp: on`, and step-up re-auth before creating and
  before *Revoke all*.
- **Final gate:** `bundle-req-audit` at 100 %, then the REQ-SEC-004 batch audit over S0 to S5, then `1.0.0`.

Distance to 1.0.0: large (four of six slices not started).

## Owner decisions (2026-11-12)

Every owner question of the contract is answered with its recommendation:

| # | Decision |
| --- | --- |
| Q-O1 | A new bundle `nowo-tech/api-token-bundle`, not an AuthKit feature |
| Q-O2 | Row id: UUIDv7 `string(36)` with binary collation |
| Q-O3 | Token = prefix + lookup id + `_` + secret + CRC32 checksum; SHA-256 at rest, no pepper by default (`hash.version: 2` optional) |
| Q-O4 | AuthKit adapter for re-auth; AuthKit gains a public `StepUpInterface` in a minor; `PasswordReauthGate` until then |
| Q-O5 | Tokens always expire, max 365 days; hosts may lower, never lift |
| Q-O6 | 0.x tag per slice after each immediate audit; Beacon adopts 1.0.0 only |
| Q-O7 | No organisation / project tokens in 1.x |
| Q-O8 | No TimeTrack module in 1.0 |
| Q-O9 | Public revocation endpoint shipped in S5, off by default |
| Q-O10 | Bridges live inside ApiTokenBundle, active when the class exists |
| Q-O11 | XML mapping by default plus an attribute-mapped twin counts as `REQ-ORM-001` compliant ("attributes or XML; never annotations") |
| Q-O12 | `OwnerRef` copied per bundle in 1.0 (no shared contracts package yet) |

## Open questions for Beacon

Answered by Beacon as recommended (Beacon D-20261111-04, 2026-11-12); kept for reference (contract §13, "For Beacon").

| # | Question | Recommendation |
| --- | --- | --- |
| Q-B1 | Keep `bt_` for new tokens in the v2 format, legacy `bt_<43>` accepted until they expire? | Yes |
| Q-B2 | Migrated tokens get `mcp: on` (no behaviour change), or no MCP grant? | `mcp: on` for migrated tokens; new tokens default to no MCP grant |
| Q-B3 | Does the McpKit bridge's `ApiTokenMcpContext` replace `BeaconMcpContext`, or do your tools need more? | Use the bridge's context; tell us what else your tools read |
| Q-B4 | Accept re-auth (password or MFA, 5-minute window) before creating a token and *Revoke all*? | Yes |
| Q-B5 | Keep `UserActionType::TaskApiTokenCreated/Revoked` through a listener, or use the AuditKit bridge's `api_token.*` actions? | Keep your enum through a 30-line listener |
| Q-B6 | Keep `ApiCredential` for `read_token`; erase usage counters on revoke? | Keep it for `read_token`; do not erase counters on revoke, only on person erasure |
| Q-B7 | `account_disabled`: delete the tokens, or only refuse them while disabled? | Only refuse (re-enabling restores the integrations) |
| Q-B8 | Table name `nowo_api_token`, or a Beacon name through `table_prefix`? | `nowo_api_token`; drop `task_api_token` one release after the copy |
