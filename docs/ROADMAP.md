# Roadmap

Built slice by slice from [CONTRACT.md](CONTRACT.md) (contract 221-A). Every slice is a pull request on
`nowo-tech/ApiTokenBundle`. S1 to S5 are **authentication slices**: each needs an **immediate REQ-SEC-004 audit**
before any tag that contains it (batch-audit rule 2026-11-07). Tags 0.1.0 … 0.5.0 per slice after each audit; Beacon
adopts only 1.0.0 in production (Q-O6).

## Table of contents

- [Slices](#slices)
- [Owner decisions (2026-11-12)](#owner-decisions-2026-11-12)
- [Open questions for Beacon](#open-questions-for-beacon)

## Slices

| Slice | Version | Content | Audit |
| --- | --- | --- | --- |
| **S0** scaffold | `main`, no tag | Composer, Docker, Makefile + release-check, CI matrix, PHPStan + worker rulesets, Igor, Rector, CS, coverage gate, Spec Kit baseline, docs set, `.github`, release / sync workflows, Flex recipe, demo skeleton (FrankenPHP worker, MySQL), threat model, strict config tree `enabled: false`. **Then the owner registers the package on Packagist** | Batched (no runtime code) |
| **S1** core (PR #2; audit LW findings fixed, delta re-audit pending) | 0.1.0 | Domain + XML mapping (+ attribute twin, Q-O11) + ID mode + table prefix, `DbalApiTokenStore`, format / hash / checksum, issuer, revoker, query, authenticator service, module registry, `Grants`, `Reach`, `OwnerRef` and the other value objects, owner resolver / status defaults, events after commit, purge command, CLI `nowo:api-token:issue` | **Immediate** |
| **S2** firewall + limits | 0.2.0 | `ApiTokenFirewallAuthenticator`, request stamp, `on_failure`, stateless check, rate limiters, failed-IP limiter, `ReachFilterInterface` chain, host-API docs | **Immediate** |
| **S3** account + operator UI | 0.3.0 | Account page, *New token* dialog, re-auth gate + framing headers, revoke / revoke all, operator page, translations ×7, templates doc | **Immediate** |
| **S4** bridges | 0.4.0 | TaskBoard, Wiki, McpKit bridges, HttpLog resolver + usage reader, UserKit eraser + export + sessions listener, AuditKit recorder, AuthKit re-auth adapter, AppTokenSource adapter | **Immediate** |
| **S5** public API + 1.0 | 0.5.0 → **1.0.0** | `<prefix>/tokens/v1/self` GET / DELETE, optional revocation endpoint, OpenAPI, legacy Beacon v1 path + `legacy-report`, `ApiTokenExpiringEvent` + command, `PurgeExpiredApiTokensMessage` + handler + schedule (the purge command enqueues by default, `--sync` for direct runs), docs, `bundle-req-audit` 100 % | **Immediate**, then 1.0.0 |
| Beacon | after 1.0.0 | Migration (contract §9), `security.yaml`, delete ≈ 1,600 lines | Beacon's own |

TimeTrack bridge (Q-O8) and organisation / project tokens (Q-O7) are 1.x minors at the earliest.

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

Still open (contract §13, "For Beacon"); the recommendation is what the bundle will assume unless Beacon answers
otherwise before S5.

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
