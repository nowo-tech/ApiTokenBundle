> **Owner decision 2026-11-12:** extraction approved; every Q-O question below is answered with its
> recommendation. The Q-B questions (Beacon) stay open: see [ROADMAP.md](ROADMAP.md#open-questions-for-beacon).
> This copy is the design contract of the bundle; the code follows it slice by slice (S0 = scaffold only).

> **Amendments 2026-11-11 (S1 audit LW, 0.x contract changes):** §2 `doctrine/dbal ^4.0` (Q2); §4.1 `IssuedToken`
> keeps its secret private behind `secret()` (LW-L1); §4.3 owner status uses the host's firewall user checkers or
> `owner.user_checker` (LW-M1) and resolvers read fresh state (LW-M2); §5.1 mapping path
> `src/Resources/config/doctrine-mapping/` (Q5) and the `name_key` column (LW-I3); §6.6 issue checks the owner (LW-L2)
> and the 0.x purge command runs directly (Q3); §10.1 / §12 S5 enqueue the purge by default.

## Table of contents

- [1. Scope](#1-scope)
- [2. Package](#2-package)
- [3. Shape](#3-shape)
- [4. Public ports (interfaces and value objects)](#4-public-ports-interfaces-and-value-objects)
- [5. Persistence](#5-persistence)
- [6. Security design](#6-security-design)
- [7. Configuration](#7-configuration)
- [8. UI (UiKit + FormKit, REQ-UI-001/003, REQ-TWIG-005)](#8-ui-uikit--formkit-req-ui-001003-req-twig-005)
- [9. Beacon migration (what Beacon deletes)](#9-beacon-migration-what-beacon-deletes)
- [10. Worker-mode design (FrankenPHP, kernel reset false)](#10-worker-mode-design-frankenphp-kernel-reset-false)
- [11. Test plan (coverage gate ≥ 99 % PHP, ≥ 90 % TS)](#11-test-plan-coverage-gate--99--php--90--ts)
- [12. Slices](#12-slices)
- [13. Questions](#13-questions)
- [Review notes v0.2 (2026-11-11, after the account switch)](#review-notes-v02-2026-11-11-after-the-account-switch)


# Contract 221-A: personal API tokens (ApiTokenBundle). Contract v0.1 for symfony-beacon and the matrix apps

Status 2026-11-11: **v0.2, design only, awaiting the owner (Q-O) and Beacon (Q-B).** No code, no repository, no PR,
no tag. v0.2 = review pass after the account switch (see "Review notes v0.2" at the end); sibling contracts:
`NOTIFY-KIT-CONTRACT.md` (221-B) and `REPORT-KIT-CONTRACT.md` (221-C) in this folder. The owner approved the extraction on 2026-11-11 (Beacon request
`docs/ops/BUNDLES-REQUESTS/2026-11-11-reaudit-extraction-candidates.md`, item 1).

Read for this contract (2026-11-11):

| Package / source | Version read | What was read |
| --- | --- | --- |
| symfony-beacon (read-only) | `master` `23a994a15` | `src/Tasks/Entity/TaskApiToken.php`, `Tasks/Api/{TaskApiTokens,TaskApiOrganizations,TaskApiSettings,TaskApiAppTokenSource}.php`, `Tasks/Api/Hook/*Authenticator*`, `*RateLimiter*`, `Tasks/Api/Security/TaskApiFirewallAuthenticator.php`, `Tasks/Controller/AccountTaskApiTokensController.php`, `Tasks/PersonalData/TaskApiTokensEraser.php`, `Wiki/Api/BeaconWikiApiAuthenticator.php`, `Mcp/Security/*`, `Identity/Session/AppToken/*`, `Shared/Http/ApiCredential.php`, `config/packages/{security,rate_limiter}.yaml` |
| TaskBoardBundle | v2.11.0 | `src/Api/TaskApiPrincipal.php`, `TaskApiLevel`, `TaskApiWhere`, `TaskApiAuthFailure`, `TaskApiCredential`, `Api/Hook/*`, `docs/API.md` ("the bundle stores no tokens") |
| WikiBundle | v1.28.0 | `src/Api/WikiApiPrincipal.php`, `WikiApiLevel`, `WikiApiWhere`, `WikiApiAuthFailure`, `Api/Hook/*` |
| McpKitBundle | v1.0.0 | `Security/McpPrincipal`, `McpAuthenticatorInterface`, `McpRateLimiterInterface`, `McpCredential`, `hooks.*` |
| TimeTrackBundle | v1.18.0 | `Entity/ClientToken` (its own hashed client session tokens), `Client/*` hooks |
| AuthKitBundle | v1.43.0 | no API token, no re-auth port, no account-security extension point; owner keys `user_type`/`user_id` |
| UserKitBundle | v1.14.1 | `PersonalData/*` (eraser, export provider, tags), `Sessions/UserSessionsInterface`, `UserSessionsRevokedEvent` |
| UiKitBundle | v2.47.0 | `_dialog`, `_open_modal_on_load`, Stimulus `temporary-reveal` + `clipboard-copy`, `View\ListPage`/`Table`/`SettingsPage` |
| HttpLogBundle | v1.16.3 | `HttpLogRequestContextResolverInterface`, `HttpLogRequestContext`, `HttpLogUsageReaderInterface`, `HttpLogUsageEraserInterface` |
| AuditKitBundle | v1.13.0 | `ActionLogRecorderInterface` |
| Rules | 2026-11-11 | `BUNDLES_FIX_RULES.md` (architecture bar 1–8, worker mode, binary collation LQ, versioned API paths, new-bundle bar), `BUNDLES_FULL_SPECS_DETAILS.md`, app REQs `REQ-API-001…003`, `REQ-AUTH-013`, `REQ-ENCRYPT-002`, `REQ-SESSION-004`, `REQ-DATA-001…004` in `OTHER_FULL_SPECS_DETAILS.md` |

Facts that shaped the design:

1. **TaskBoard, Wiki and McpKit each say "the bundle stores no tokens"** and expose an authenticator hook that returns
   their own principal. They already carry `tokenId`, `tokenName`, a level enum, a *where* value and a `channel`. The
   missing piece is the store, the issuer and the glue, and every app rewrites it today. That is this bundle.
2. Beacon's token model lives in `Tasks` (183 + 168 + 38 lines) but serves three modules. Levels are columns per
   module (`level`, `wiki_level`, `mcp_destructive`), so adding a module means a migration. The bundle stores
   **grants as data** (`{module: level}`), and modules register their levels.
3. Beacon looks a token up by its full SHA-256 (unique index). That works, but it gives no non-secret lookup id
   (`REQ-ENCRYPT-002` asks for one) and no cheap way to tell "unknown" from "wrong secret" for the audit.
4. `TaskApiTokens::resolve()` runs in the firewall and **flushes the host's default EntityManager** to write
   *last used*. A bundle must not do that (rule 6, worker section). The bundle writes *last used* through DBAL, at
   most once a minute per token.
5. Token creation in Beacon has **no re-authentication** and no framing headers (`REQ-AUTH-013` lists API tokens).
   The bundle closes that gap.
6. AuthKitBundle is 27,700 lines of sign-in features and has no API token, no Bearer authenticator and no re-auth
   port. sms-bridge, CRM v2 and Age Desk use AuthKit, but an API-only host should not need AuthKit's whole sign-in
   stack to issue tokens.

---

## 1. Scope

**New bundle or AuthKit feature?** **Recommendation: a new bundle**, `nowo-tech/api-token-bundle` (Q-O1).

| | New bundle (recommended) | AuthKit feature `api_tokens` |
| --- | --- | --- |
| Hosts without AuthKit sign-in (API-only workers, sms-bridge enqueue API) | Work | Must install AuthKit |
| Audit surface | Small and isolated: the SEC-004 audit covers only token code, and an audit finding does not block AuthKit releases | Every AuthKit release re-opens the token audit |
| Dependencies | Optional bridges to TaskBoard, Wiki, McpKit, TimeTrack, HttpLog, UserKit, AuditKit, AuthKit | AuthKit gains six more optional edges |
| Re-auth (`REQ-AUTH-013`) | Own `ReauthGateInterface`, with an AuthKit adapter when AuthKit has MFA | Direct |
| Cost | One more repository | None |

**In:**

- **Store and lifecycle.** Personal tokens are issued to an owner (a user, referenced by id). Each token has a name,
  per-module grants (level and flags), a *where* list, an expiry, revoke, revoke-all and erasure. Hash at rest, shown
  once, with a non-secret lookup id.
- **Authentication.**
  - One Bearer authenticator for Symfony firewalls.
  - The principal is stamped on the request.
  - Adapters build the TaskBoard / Wiki / McpKit / TimeTrack principals from the same token.
- **Security controls.**
  - Rate limits: a shared bucket per token, a write sub-budget, and failed tokens per client IP.
  - Re-auth before issue.
  - Audit events.
  - HttpLog credential stamp.
  - UserKit eraser and export provider.
  - *Apps and tokens* adapter for the host's sessions page.
- **UI.** An account page (list, *New token* dialog shown once, revoke, revoke all) and an operator page (list,
  revoke).
- **Public API.** `GET` / `DELETE <prefix>/tokens/v1/self` (introspection, self-revoke) with OpenAPI.

**Non-goals (v1):**

- **No OAuth.** No OAuth 2 server: no authorization code, no refresh tokens, no client credentials, no device flow.
  TimeTrack keeps its device flow and its own client session tokens.
- **No project/app keys.** Organization or project tokens (machine credentials not tied to a person) are out of v1
  (Beacon's ingest keys and read keys stay in Beacon). Q-O7 asks whether v2 should add them.
- **No signing keys.** No HMAC request signing and no keys that both authenticate and sign (`REQ-ENCRYPT-002` HKDF
  case): no consumer needs one.
- **No business decisions.** The bundle does not decide **what a module allows**. Each API still checks the person's
  current permissions on every call. The token only narrows them (`REQ-API-002`: "never more").
- **No usage page.** The API usage page stays with HttpLog (Beacon request item 12).

## 2. Package

| Item | Proposal |
| --- | --- |
| Composer | **`nowo-tech/api-token-bundle`** |
| GitHub | `nowo-tech/ApiTokenBundle` (REQ-DOCS-014), public, MIT; the owner creates it and registers it on Packagist after S0 is on `main` |
| Namespace / class | `Nowo\ApiTokenBundle` / `NowoApiTokenBundle`; config root `nowo_api_token`; Twig namespace and translation domain `NowoApiTokenBundle`; asset package `nowo_api_token` |
| PHP / Symfony | `>=8.2 <8.6`; `^7.4 \|\| ^8.0` (REQ-SF-001); demo Symfony 8.1 + FrankenPHP worker, MySQL (REQ-DEMO-010/011/012) |
| Runtime deps | `symfony/{http-foundation,http-kernel,routing,config,dependency-injection,event-dispatcher,security-core,security-http,rate-limiter,uid,clock}`, `doctrine/dbal ^4.0` (amended, Q2: the host-transaction join relies on DBAL 4 savepoints), `doctrine/orm ^3.3` (XML mapping only), `psr/log`, `nowo-tech/ui-kit-bundle`, `nowo-tech/form-kit-bundle` (REQ-TWIG-005), `twig/twig` |
| Suggest | `nowo-tech/task-board-bundle`, `wiki-bundle`, `mcp-kit-bundle`, `time-track-bundle` (module bridges); `user-kit-bundle` (eraser, export, account status); `http-log-bundle` (credential stamp, *last used* counts); `audit-kit-bundle`; `auth-kit-bundle` (re-auth with MFA); `nelmio/api-doc-bundle` is **not** needed (static OpenAPI file) |
| First tag | 1.0.0 after S5, the REQ audit and the SEC-004 audit (Q-O6 asks about 0.x tags per slice) |

## 3. Shape

```
Client ── Authorization: Bearer bt_k7m2q9x4ab3c_<secret> ──▶ host firewall (stateless, ^%nowo_api_token.api_prefix%/(tasks|wiki|tokens)/ , ^/mcp$)
                                                              │  ApiTokenAuthenticator (§6.2): parse → lookup id → hash_equals → status → owner active
                                                              ▼
                                       ApiTokenPrincipal on request attribute `_nowo_api_token` (never on a service)
                                                              │
         ┌──────────────────────┬──────────────────────┬──────┴───────────────┬───────────────────────┐
         ▼                      ▼                      ▼                      ▼                       ▼
 TaskBoard bridge        Wiki bridge             McpKit bridge          TimeTrack bridge        host's own API
 TaskApiAuthenticator    WikiApiAuthenticator    McpAuthenticator       (Q-O8)                  (reads the principal)
 + RateLimiter           + RateLimiter           + RateLimiter
 (TaskApiPrincipal)      (WikiApiPrincipal)      (McpPrincipal, grants, destructive flag)
                                                              │
                     after commit: ApiTokenIssued / Revoked / AuthFailed events ─▶ AuditKit bridge, host listeners
                     request stamp ─▶ HttpLog resolver (kind `personal_token`, id = token id)
```

The bundle owns the token, the secret format, the hash, the lookup, the status checks, the rate limit
implementations, the principal, the module registry, the account and operator pages, the events and the personal
data adapters. The host owns:

- who the owner is and whether the account is active;
- which *where* entries a person may choose (`ReachProviderInterface`);
- per-request narrowing, such as an organization that forbids tokens (`ReachFilterInterface`);
- the plan gate (`ApiTokenPolicyInterface`);
- every business permission inside the APIs.

## 4. Public ports (interfaces and value objects)

All in `Nowo\ApiTokenBundle\Contract\*` (layered namespaces: `Domain`, `Application`, `Infrastructure`,
`Presentation`, `Bridge`). No Doctrine, Symfony or HTTP type appears in a port, VO or event (rule 3). The only
exceptions are the Symfony authenticator class itself and `ApiTokenContext::current(Request)`, which live in
`Infrastructure\Security`.

### 4.1 Value objects

```php
final readonly class OwnerRef            // the person; ID mode (§5.2)
{
    public function __construct(public string $type, public string $id) {} // type ^[a-z][a-z0-9_]{0,31}$, id 1–128 chars, no control chars, never trimmed
    public function key(): string;       // "<type>:<id>"
    public function equals(self $other): bool; // strict ===
}

final readonly class TokenId { public function __construct(public string $value) {} }        // UUIDv7, RFC 4122 string
final readonly class LookupId { public function __construct(public string $value) {} }       // 12 chars [a-z2-7], non-secret

enum ModuleLevelMode { case Ordered; case Flags; }                                           // a ladder or independent ticks

final readonly class ModuleDefinition
{
    /** @param list<string> $levels ordered low → high, e.g. ['my_tasks','read','tasks','full']; @param list<string> $flags e.g. ['destructive'] */
    public function __construct(public string $key, public array $levels, public array $flags = [], public string $labelKey = '', public ?string $firstWriteLevel = null, public array $requiresModules = []) {}
}

final readonly class Grants
{
    /** @param array<string,string> $levels module => level; @param array<string,list<string>> $flags module => ticked flags */
    public function __construct(public array $levels, public array $flags = []) {}
    public function level(string $module): ?string;                     // null = no access to the module
    public function atLeast(string $module, string $level, ModuleRegistry $registry): bool;
    public function hasFlag(string $module, string $flag): bool;
}

final readonly class Reach
{
    /** @param list<string> $entries e.g. ['<project-uuid>', 'org-<uuid>', 'general', 'wiki:<slug>', 'board:<id>'] */
    public static function everything(array $except = []): self;
    public static function limitedTo(array $entries): self;             // empty list = reaches nothing, never widens
    public function isEverything(): bool;
    public function entries(?string $kind = null): array;               // kind = prefix before ':', null = bare tenant keys
    public function without(array $entries): self;                      // used by ReachFilterInterface
}

final readonly class ApiTokenView      // what lists and events see; never the secret or the hash
{
    public function __construct(
        public TokenId $id, public LookupId $lookupId, public OwnerRef $owner, public string $name,
        public Grants $grants, public Reach $reach, public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $expiresAt, public ?\DateTimeImmutable $lastUsedAt, public ?string $createdVia, // 'account' | 'cli' | null
    ) {}
}

final readonly class ApiTokenPrincipal  // built per request by the authenticator
{
    public function __construct(
        public TokenId $tokenId, public string $tokenName, public OwnerRef $owner,
        public object $user,              // the host's user object, resolved by OwnerResolverInterface
        public Grants $grants, public Reach $reach, public \DateTimeImmutable $expiresAt,
    ) {}
}

final readonly class IssuedToken implements \JsonSerializable // returned once (amended, LW-L1)
{
    public function __construct(public ApiTokenView $token, #[\SensitiveParameter] string $secret) {} // secret kept private
    public function secret(): string;            // the only way to read it
    public function jsonSerialize(): array;      // ['token' => view, 'secret' => '[redacted]']
    public function __serialize(): array;        // throws LogicException; __unserialize() too
    public function __debugInfo(): array;        // redacted (print_r, var_dump, VarDumper)
}

final readonly class IssueRequest
{
    public function __construct(public OwnerRef $owner, public string $name, public Grants $grants, public Reach $reach, public int $expiresInDays, public string $createdVia = 'account') {}
}

enum RevocationReason: string { case Owner = 'owner'; case OwnerAll = 'owner_all'; case Operator = 'operator'; case SelfApi = 'self_api'; case Erasure = 'erasure'; case Compromised = 'compromised'; case Expired = 'expired_purge'; }
enum AuthFailure: string { case Missing = 'missing'; case Malformed = 'malformed'; case Unknown = 'unknown'; case WrongSecret = 'wrong_secret'; case Expired = 'expired'; case OwnerInactive = 'owner_inactive'; case Disabled = 'disabled'; }
```

`AuthFailure` is internal and goes into events and logs. Every case answers the client identically (§6.3).

### 4.2 Application services (bundle-implemented)

```php
interface ApiTokenIssuerInterface
{
    /** Validates grants against the registry, reach against ReachProviderInterface, the TTL against policy; persists; dispatches ApiTokenIssuedEvent after commit. */
    public function issue(IssueRequest $request): IssuedToken;
}

interface ApiTokenRevokerInterface
{
    public function revoke(OwnerRef $owner, TokenId $id, RevocationReason $reason, ?OwnerRef $actor = null): bool; // false: not found / not the owner's
    public function revokeAll(OwnerRef $owner, RevocationReason $reason, ?OwnerRef $actor = null): int;
    public function revokeAsOperator(TokenId $id, OwnerRef $operator): bool;                                        // operator page, audited
}

interface ApiTokenQueryInterface
{
    /** @return list<ApiTokenView> newest first, capped by max_per_owner */
    public function ofOwner(OwnerRef $owner): array;
    public function find(TokenId $id): ?ApiTokenView;
    /** Operator page: keyset pagination (REQ-PERF-001). */
    public function page(?string $after, int $limit, ?OwnerRef $owner = null): ApiTokenPage;
}

interface ApiTokenAuthenticatorInterface
{
    /** Pure check, no side effect other than the throttled last-used write. */
    public function authenticate(#[\SensitiveParameter] string $presented): ApiTokenPrincipal|AuthFailure;
}

final class ApiTokenContext { public static function current(Request $request): ?ApiTokenPrincipal; } // request attribute only
```

### 4.3 Host SPIs (host-implemented; every one has a safe default)

| Interface | Signature | Default |
| --- | --- | --- |
| `OwnerResolverInterface` | `ownerOf(object $user): OwnerRef` · `userOf(OwnerRef $owner): ?object` | `DoctrineOwnerResolver`: `user.class` + identifier field (`id`) through the host's ORM, type `user`; re-reads the user from the database on every call (refresh query) and detaches it again when it loaded it, so a deactivation by another process is seen on the next request and the identity map does not grow (amended, LW-M2). DDD hosts implement it and must never answer from a cache or an identity map |
| `OwnerStatusInterface` | `isActive(object $user): bool` | `UserCheckerOwnerStatus`: runs `checkPreAuth()` (`AccountStatusException` → inactive) of the host's checkers: with `owner.user_checker: null` every firewall's `security.user_checker.<firewall>` chained (so the app's own checker and UserKit's `AccountStatusUserChecker` run), or the `owner.user_checker` service, or none with `owner.user_checker: none`. The container refuses to build when no firewall has a checker other than Symfony's stock `security.user_checker`. With a checker, a non-`UserInterface` user is inactive (amended, LW-M1) |
| `ReachProviderInterface` | `choicesFor(OwnerRef $owner): list<ReachChoice{key, label, group}>` | Returns no choices: only *everything* can be chosen when `reach.allow_everything` is true, otherwise the form refuses |
| `ReachFilterInterface` (tagged, chained) | `filter(ApiTokenPrincipal $p, Reach $reach): Reach` | Identity. Beacon: organizations that forbid tokens (today `TaskApiOrganizations`) |
| `ApiTokenPolicyInterface` | `mayIssue(OwnerRef $o): bool` · `modulesFor(OwnerRef $o): list<string>` · `maxTtlDays(OwnerRef $o): int` | Config values (PlanKit can implement it later) |
| `ReauthGateInterface` | `isFresh(object $user, string $sessionKey): bool` · `form(): ?string` (FormKit type of the re-auth step) · `verify(object $user, array $data): bool` | `PasswordReauthGate`: the user re-enters the password (Symfony `UserPasswordHasherInterface`), at most 5 attempts per session per 15 min; freshness window `reauth.fresh_seconds` (300). AuthKit adapter (Q-O4) adds MFA |
| `TokenRevocationListenerInterface` (tagged) | `onRevoked(ApiTokenView $token, RevocationReason $reason): void` | None. Bridges: TaskBoard and Wiki idempotency `forget()` with each bundle's own scope function |
| `ApiTokenSettingsProviderInterface` (rule 8) | `enabled(): bool` · `modulesEnabled(): array<string,bool>` | Config values. InstanceSettings bridge optional (`TASK_API_ENABLED`/`MCP_ENABLED` move there in Beacon) |

## 5. Persistence

### 5.1 Entity and XML mapping (bundle-owned, referenced by ID: rule 2)

`Nowo\ApiTokenBundle\Domain\ApiToken` is a plain PHP class with no attributes, mapped by
`src/Resources/config/doctrine-mapping/ApiToken.orm.xml` (amended, Q5: not `config/doctrine`, which DoctrineBundle
auto-maps by bundle convention under the wrong namespace in hosts that keep the bundle disabled). The bundle prepends the mapping (type `xml`, `is_bundle: false`,
prefix `Nowo\ApiTokenBundle\Domain`) on the entity manager named in `doctrine.entity_manager` (default `default`).

- **Standard hosts:** zero config. The table appears in `doctrine:migrations:diff`. Hosts may query the entity (read
  only) and reference a token by id.
- **DDD hosts:** set `doctrine.auto_mapping: false`, register the same XML on their own EM or copy it, and reference
  tokens by `TokenId`.

The bundle never relies on the EntityManager to write (§5.4), so the mapping exists for schema and reads.

Table `<prefix>api_token` (REQ-ORM-002: `doctrine.table_prefix`, default `nowo_`, via a scoped `TablePrefixListener`):

| Column | Type | Notes |
| --- | --- | --- |
| `id` | `string(36)`, **binary collation**, PK | UUIDv7 from `symfony/uid`, generated by the bundle (no auto-increment, so no enumeration, and ids are stable across exports) |
| `lookup_id` | `string(12)`, binary, **unique** | Non-secret, random 60 bits, `[a-z2-7]` (base32 lower). Indexed lookup |
| `secret_hash` | `string(64)`, binary | `sha256(full token)` in lower hex (§6.1); `hash_version` says which |
| `hash_version` | `smallint` | 1 = SHA-256; 2 = HMAC-SHA256 with pepper (Q-O3); 0 = Beacon legacy (§9) |
| `owner_type` | `string(32)`, binary | `OwnerRef::$type` |
| `owner_id` | `string(128)`, **`utf8mb4_bin`** | LQ rule; capped and normalised at the port |
| `name` | `string(80)` | Trimmed, no control characters |
| `name_key` | `string(80)` | Lower-cased name; unique index `uniq_<p>api_token_owner_name (owner_type, owner_id, name_key)` makes names unique per owner, case-insensitively, under concurrency (amended, LW-I3) |
| `grants` | `json` | `{"levels": {"tasks": "read"}, "flags": {"mcp": ["destructive"]}}`, validated against the registry on write **and** on read (an unknown module or level is dropped, never widened) |
| `reach_everything` | `bool` | |
| `reach` | `json` | List of entries (≤ 200, each ≤ 128 chars, `^[A-Za-z0-9._:-]+$`) |
| `created_at`, `expires_at` | `datetime_immutable` (UTC) | `expires_at` NOT NULL (no endless tokens, Q-O5) |
| `last_used_at` | `datetime_immutable` NULL | DBAL update, throttled to 60 s per token |
| `created_via` | `string(16)` | `account`, `cli` |
| `legacy_hash` | `string(64)`, binary, NULL, **unique** | Only for migrated Beacon v1 rows (`hash_version 0`, §9.1): the old full-token SHA-256. NULL for every new token (MySQL allows many NULLs in a unique index; PostgreSQL uses a partial index). Dropped in the next major |

Indexes: `uniq_<p>api_token_lookup (lookup_id)`, `idx_<p>api_token_owner (owner_type, owner_id, created_at)`,
`idx_<p>api_token_expires (expires_at)`.

### 5.1.1 Mapping options (standard and DDD)

`REQ-ORM-001` rule 1 asks for attribute mapping, while the DDD bar (rules 1–2) asks for a plain domain class a strict
host can map in XML. The bundle therefore ships **both** for the same class: `Domain\ApiToken` stays attribute-free
and the bundle registers its XML mapping by default (`doctrine.mapping: xml`), and an attribute-mapped
`Infrastructure\Doctrine\Entity\ApiTokenRecord` (same table, same columns) is available with `doctrine.mapping:
attribute` for hosts whose tooling only reads attributes. A test asserts that both produce the same schema. Q-O11 asks
the owner to confirm that XML-by-default counts as compliant with `REQ-ORM-001` for a bundle-owned, ID-referenced
entity.

On PostgreSQL and SQLite the binary collation is a no-op (exact by default; a test asserts it). On MySQL/MariaDB the
mapping sets `options: {collation: utf8mb4_bin}` (`ascii_bin` for the id-like ascii columns). Every query that grants
access or returns or erases data by `owner_id` re-checks `OwnerRef::equals()` in PHP (LQ rule).

**Revocation deletes the row** (data minimisation, as Beacon does today). The `ApiTokenRevokedEvent` and the audit
row keep the id, the name and the reason. No soft-revoked secret hash is kept.

### 5.2 ID mode

- **Bundle rows:** UUIDv7 strings (36 chars, binary collation). This matches TaskBoard, Wiki and TimeTrack ids.
  `binary(16)` was rejected for consistency and readable exports (Q-O2).
- **Owner:** `OwnerRef(type, id)`, two strings, never a Doctrine relation to the host's user class. The bundle
  therefore needs no `resolve_target_entities` and no FK to a host table. Erasure goes through the eraser (§8.4,
  `REQ-DATA-001`), not through `ON DELETE CASCADE`.

### 5.3 Schema and migrations (`REQ-ORM-001` rule 5, `REQ-ORM-002`)

- The bundle ships **no Doctrine migration classes** (house style: TaskBoard, Wiki, TimeTrack). Standard hosts run
  `doctrine:migrations:diff`; the result is checked in `docs/INSTALLATION.md` against reference DDL for MySQL 8 /
  MariaDB 11, PostgreSQL 16 and SQLite (collations included), generated by a test so it cannot drift.
- `bin/console nowo:api-token:schema-check` compares the live table with the mapping (columns, collations, indexes,
  table prefix) and exits non-zero on a difference; `make release-check` of the demo runs it.
- The demo keeps its own `migrations/` folder (REQ-DEMO), as the other bundles do.
- Every schema change in 1.x is additive and listed in `UPGRADING.md` with its DDL.

### 5.4 Writes (rule 6, worker mode)

`DbalApiTokenStore` (implements `ApiTokenStoreInterface`, replaceable) writes through the DBAL connection of the
configured EM, in an explicit transaction it opens and closes in the same call. It never calls `flush()` on any
EntityManager and never keeps a managed entity. Reads hydrate `ApiTokenView` / internal rows from DBAL. If the
connection dropped between requests, it reconnects (DBAL 4 reconnects lazily; the store retries once on
`ConnectionLost`). Writes inside a host transaction (the host called `issue()` while its own transaction is open on
the same connection) join it: events are held until the outermost commit and dropped on rollback, which is the
InstanceSettings `AfterCommitEventDispatcher` pattern, copied, not imported.

## 6. Security design

### 6.1 Token format and hashing

```
<prefix><lookup_id>_<secret>[<checksum>]
bt_     k7m2q9x4ab3c _ 43 chars base64url (32 random bytes)   + 6 chars base62 CRC32 (Q-O3)
```

- `prefix`: config `token_prefix`, default `nwt_`, `^[a-z]{2,8}_$`. Beacon sets `bt_` (its current prefix, so
  secret scanners and its docs keep working).
- `lookup_id`: 12 chars from `random_bytes`, unique (retry on collision), stored in clear, shown in lists as the
  token's public handle (`bt_k7m2…`).
- `secret`: 32 bytes from `random_bytes` (256 bits), base64url without padding.
- `checksum` (recommended, Q-O3): CRC32 of `prefix + lookup_id + _ + secret` in base62, 6 chars. It lets the
  authenticator reject typos without a DB read, and lets GitHub secret scanning or gitleaks recognise the token with
  few false positives.
- **Hash at rest** (`REQ-ENCRYPT-002`): `hash_version 1` = `hash('sha256', $fullToken)`. The secret has 256 bits of
  entropy, so a slow hash (bcrypt/argon2) adds latency on every API call and no security. An optional pepper
  (`hash_version 2`, HMAC-SHA256 with a key from `%env(NOWO_API_TOKEN_PEPPER)%`) is Q-O3.
- **Shown once.**
  - The secret exists only in `IssuedToken`, which goes into the POST response that created it. It is never put in a
    flash, the session, a log, an event, an exception message or the cache.
  - The page sends `Cache-Control: no-store` (and `private`).
  - The dialog uses UiKit `temporary-reveal` with `clearOnHide` and `clipboard-copy`, so the plaintext leaves the DOM
    when the person closes it (`REQ-ENCRYPT-002` UX).
  - A reload shows the list only. A lost secret means a new token.

### 6.2 Verification (constant time, order fixed)

`ApiTokenAuthenticator::authenticate(string $presented)`:

1. Length > 128 or not matching `^<prefix>([a-z2-7]{12})_([A-Za-z0-9_-]{43})([0-9A-Za-z]{6})?$` → `Malformed`. A
   legacy Beacon token (`^bt_[A-Za-z0-9_-]{43}$`, §9) goes to the legacy path when `legacy.beacon_v1` is on.
2. Checksum present and wrong → `Malformed` (no DB read).
3. Load the row by `lookup_id` (unique index, binary). If there is no row, compute the hash anyway and
   `hash_equals()` it against a fixed dummy, so a miss and a mismatch take the same time → `Unknown`.
4. `hash_equals($row->secretHash, hash(...))`. Never `===`, never a SQL `WHERE secret_hash = ?` → `WrongSecret`.
5. `expires_at <= now` (PSR clock) → `Expired`.
6. Module switch (`ApiTokenSettingsProviderInterface::enabled()`) off → `Disabled`.
7. `OwnerResolverInterface::userOf()` null, or `OwnerStatusInterface::isActive()` false → `OwnerInactive`. This is
   checked on **every** request, so a disabled, erased or banned person is refused at once, with no cache.
8. Grants re-validated against the registry (unknown entries dropped). `ReachFilterInterface` chain applied.
9. Principal built. *Last used* written when older than 60 s: one DBAL `UPDATE … WHERE id = ? AND (last_used_at IS
   NULL OR last_used_at < ?)`, outside any host transaction; a failure is logged at debug level and ignored.

**Credential source:** the `Authorization` header only, scheme `Bearer` (case-insensitive) or bare, which is today's
TaskBoard / Wiki / McpKit behaviour (`*Credential::fromRequest`). A query string, cookie or body credential is never
read. A request that carries both a session cookie and a token is decided by the token, because the firewall is
stateless.

### 6.3 Firewall integration and refusals

- `Nowo\ApiTokenBundle\Infrastructure\Security\ApiTokenFirewallAuthenticator` (Symfony `AbstractAuthenticator`):
  - `supports()` is true when an `Authorization` header is present.
  - On success it returns a `SelfValidatingPassport` for the resolved user and stamps the principal on the request.
  - On failure, `on_failure: pass` (default) lets the request through **with no user**. Each API answers its own
    401 / 403 / 404 in its own JSON shape (`REQ-API-002`, TaskBoard / Wiki / McpKit behaviour).
  - `on_failure: unauthorized` answers `401` with `WWW-Authenticate: Bearer` and `{"err": "Invalid token", "ECODE":
    "TOKEN_000"}`, for host APIs that have no own refusal.
- The same answer for every failure kind: the client never learns whether a lookup id exists.
- Every failure calls the failed-token limiter for the client IP (§6.7) and dispatches `ApiTokenAuthFailedEvent`.
- The token's user must equal the firewall's user. The bridges keep McpKit's §3.2 step-9 style check: the principal
  the API receives comes from `ApiTokenContext::current()`, which only the authenticator writes.
- The container refuses to build when `api.enabled` is true and the API's firewall pattern is not stateless (checked
  in a compiler pass reading `security.firewalls`, like WikiBundle's check).

### 6.4 Scopes and levels per API (module registry)

- Modules are declared in config (`modules:`) and by bridges (tagged `ModuleDefinition` providers). The registry is
  compiled at container build: the keys `^[a-z][a-z0-9_]{0,31}$` are unique, and the levels are unique per module.
- A token holds at most one level per module, plus ticked flags. **No grant = no access** to that module.
- Levels from the module's `firstWriteLevel` up count in the write budget (`tasks`: `tasks`; `wiki`: `edit`).
- The bridges map grants to each bundle's own enum. A value the enum does not know (e.g. after a level was removed)
  is refused, never coerced to a lower or higher level:

| Module (bridge) | Levels (ordered) | Flags | Maps to |
| --- | --- | --- | --- |
| `tasks` (TaskBoard ≥ 2.11) | `my_tasks`, `read`, `tasks`, `full` | — | `TaskApiLevel::from()` |
| `wiki` (Wiki ≥ 1.28) | `read`, `edit`, `full` | — | `WikiApiLevel::from()`; no grant → `WikiApiAuthFailure::NoWikiLevel` |
| `mcp` (McpKit ≥ 1.0) | `on` | `destructive` (off by default, its own tick in the form, `REQ-API-004`) | `McpPrincipal{grants: {tasks, wiki}, destructiveAllowed}`; requires the module and at least one of `tasks` / `wiki` |
| `time_track` (TimeTrack, Q-O8) | `read`, `write` | — | Later |
| host modules | from config | from config | the host reads `ApiTokenPrincipal::$grants` |

### 6.5 Tenant "where" (reach)

- At issue time:
  - The form offers *everything I can reach* (`reach.allow_everything`, default true) or a list built from
    `ReachProviderInterface::choicesFor()`.
  - The chosen entries are intersected with the choices. An empty result is refused.
  - The bundle never stores an entry the person could not choose that day.
- On every request:
  - The stored reach goes through the `ReachFilterInterface` chain, which can only remove entries or add exceptions to
    *everything*.
  - The bridges then build `TaskApiWhere::limitedTo(bare tenant keys, board:<id> entries)` / `WikiApiWhere::limitedTo
    (owner keys, wiki:<slug> entries)`.
  - The bundles still check the person's real access on each object, so a reach entry never grants access by itself.

### 6.6 Expiry, revoke, revokeAll, end of access

- **Expiry is mandatory.**
  - `expiry.default_days` is 90, `expiry.max_days` is 365, and `expiry.choices` defaults to `[7, 30, 90, 365]`.
  - The policy port may lower the maximum per owner.
  - Expired rows are refused at once and purged after `expiry.purge_after_days` (30). The purge runs from the
    `nowo:api-token:purge-expired` command, keyset batches of 500 by id, DBAL only, damaged expired rows included,
    start / success / failure logged. In 0.x the command purges directly (amended, Q3: CLI only, bounded batches, a
    time budget, only rows expired before the cutoff); from S5 it enqueues `PurgeExpiredApiTokensMessage` by default
    and `--sync` keeps the direct run (`REQ-MESSENGER-007/008`).
  - Issue refuses an owner that `OwnerResolverInterface::userOf()` does not find or `OwnerStatusInterface` reports
    inactive (`ApiTokenIssueRefusedException::OWNER`, amended LW-L2).
  - Optional notice N days before expiry (`expiry.notify_before_days`, default null) through `ApiTokenExpiringEvent`.
    The NotifyKit bridge turns it into a bell row and a mail (contract 221-B).
- **Revoke:**
  - The owner revokes one token (CSRF form) or all of them (CSRF + confirm dialog).
  - An operator revokes any token from the operator page.
  - The token itself can revoke itself through `DELETE /self`.
  - Each path deletes the row, then runs `TokenRevocationListenerInterface` (idempotency stores forget the token's
    answers, as TaskBoard UPGRADING 2.2.0 step 7 and Wiki 1.24.0 require), then the event after commit.
  - The next request with that token is `Unknown`.
- **End of access** (`REQ-SESSION-004`, `REQ-API-002`):
  - A disabled account is refused on every request (§6.2 step 7), and no row changes.
  - *Sign out everywhere* does **not** revoke long-lived tokens (Beacon's rule, spec 187).
  - *Revoke all* and the "it wasn't me" link do revoke them. The bundle ships a UserKit listener for
    `UserSessionsRevokedEvent` with `revoke_on_sessions_revoked: []` (configurable list of `RevocationReason` values
    from UserKit, e.g. `[account_disabled]`), plus `AppTokenSourceAdapter` for hosts that list tokens on their
    sessions page.

### 6.7 Rate limits

The bundle ships `symfony/rate-limiter` implementations of `TaskApiRateLimiterInterface`,
`WikiApiRateLimiterInterface` and `McpRateLimiterInterface`, plus its own for host APIs, all on **one bucket per
token** (key `nowo_api_token|<token id>`):

| Limit | Default (config) | Where |
| --- | --- | --- |
| Requests per token | 100 / minute, fixed window (`rate_limit.per_token`) | Every authenticated request on every module (an agent cannot double its budget by switching protocol: Beacon D-20261108-16) |
| Writes per token | 30 / minute (`rate_limit.writes`) | McpKit `consumeWrite()`; REST write levels when `rate_limit.count_rest_writes: true` |
| Failed tokens per client IP | 20 / 15 minutes (`rate_limit.failed_per_ip`) | Peeked before parsing, consumed on every failure; the IP comes from `Request::getClientIp()` (trusted proxies are the host's job, documented) |
| Issue per owner | 10 / hour (`rate_limit.issue_per_owner`) | Account page |
| Re-auth attempts | 5 / 15 minutes per session (`reauth.max_attempts`) | Re-auth step |
| Tokens per owner | 20 live tokens (`max_per_owner`) | Issue refused beyond it |

Limiter storage is the host's `cache.rate_limiter` pool (config `rate_limit.cache_pool`). With no pool, the
container does not build when `enabled: true`. The bundle keeps no counters in process memory (worker mode).

### 6.8 Audit events (ids only, after commit)

| Event (`ApiTokenEvents::*`) | Payload | When |
| --- | --- | --- |
| `ApiTokenIssuedEvent` | `tokenId`, `lookupId`, `owner` (`OwnerRef`), `name`, `grants`, `reachSummary` (`everything` or count), `expiresAt`, `createdVia` | After the insert commits |
| `ApiTokenRevokedEvent` | `tokenId`, `lookupId`, `owner`, `name`, `reason`, `actor` (`?OwnerRef`) | After the delete commits |
| `ApiTokenAuthFailedEvent` | `failure` (`AuthFailure`), `lookupId` (only for `WrongSecret`, `Expired`, `OwnerInactive`, the cases where the token exists), `clientIpHash` (HMAC-SHA256 of the IP with `kernel.secret`, 16 hex chars), `path` (route name or path template, never the query) | Every failure; listeners must be cheap, and the AuditKit bridge throttles it to one row per lookup id per hour |
| `ApiTokenUsedEvent` | `tokenId`, `owner`, `module` | Only when *last used* was written (≤ once a minute per token), so a listener cannot turn into a per-request write |
| `ApiTokenExpiringEvent` | `tokenId`, `owner`, `name`, `expiresAt` | From `nowo:api-token:notify-expiring` (enqueue only) |

Events never carry the secret, the hash, the presented credential or a raw IP. The AuditKit bridge
(`ActionLogRecorderInterface::recordAndFlush` on the host's audit EM, after commit) writes the
`api_token.issued|revoked|auth_failed` actions. Beacon keeps its own `UserActionType` mapping (Q-B5).

### 6.9 Usage accounting (`REQ-API-003`)

When HttpLogBundle is present, `HttpLogTokenContextResolver` implements `HttpLogRequestContextResolverInterface`
and returns `HttpLogRequestContext(credentialKind: 'personal_token', credentialId: <token id>)`, read from the
request attribute the authenticator set. A refused request has no attribute, so it is counted without a credential.
The account list shows *last used* and the 30-day counts from `HttpLogUsageReaderInterface::lastUsed()`, or the
bundle's own `last_used_at` column without HttpLog. On revoke and erasure the bridge calls
`HttpLogUsageEraserInterface::eraseCredential('personal_token', $id)` when `http_log.erase_usage_on_revoke` is true
(default false: the counts are operational data with no personal content; Q-B6).

### 6.10 Re-auth and framing (`REQ-AUTH-013`)

- Issuing a token (and *Revoke all*, which is destructive) requires a fresh re-auth: the password, or an MFA step
  through the AuthKit adapter. *Fresh* means within `reauth.fresh_seconds` (300).
- The freshness mark is a session value bound to the session id. A new session (another browser) has to re-auth.
- The create form posts the re-auth data and the token data in one CSRF-protected POST. A failed re-auth counts
  against the 5-attempt limit.
- The token pages send:
  - `X-Frame-Options: DENY`;
  - `Content-Security-Policy: frame-ancestors 'none'`, appended to any host CSP header, never replacing it;
  - `Cache-Control: no-store, private`;
  - `Referrer-Policy: same-origin`.
- `ReauthGateInterface` is replaceable. A host with SSO only (no password) implements it with its IdP's step-up. The
  container does not build when `reauth.enabled: true`, no password hasher exists and no gate is configured.

### 6.11 Other controls

- **Logs** (`REQ-OBS-001`): token id, lookup id and failure kind only. `#[\SensitiveParameter]` is on every parameter
  that can carry a secret, so stack traces redact it.
- **Errors:** exception messages never include the presented value.
- **CSRF** (`REQ-SEC-005`): every session POST of the account and operator pages uses a FormKit form (CSRF-only forms
  for revoke). The Bearer API is exempt, and `docs/SECURITY.md` says so.
- **Admin UI** (`REQ-UI-002`): `security.access_roles` (default `['ROLE_ADMIN']`), `security.access_checker`,
  `security.allow_unauthenticated` (false). The operator page lists owner key, name, lookup id, modules, dates and
  last used, never grants detail beyond module names. Revoke is audited with the operator as actor.
- **Account page:** `IS_AUTHENTICATED_FULLY`. A remembered-me session must re-auth anyway (§6.10).
- **Threat model** in `docs/SECURITY.md`:
  - token leak in logs or CI;
  - DB read leak (hash only);
  - lookup-id enumeration (only `Unknown` or `WrongSecret`, the same answer);
  - timing;
  - brute force (IP limiter, 2^256 secret);
  - clickjacking of the create page;
  - CSRF on revoke;
  - privilege growth (grants never widen, the person's permissions are re-checked);
  - collation (LQ);
  - worker-mode bleed between two tokens.

## 7. Configuration

```yaml
nowo_api_token:
    enabled: false                       # nothing reachable while false
    token_prefix: 'nwt_'                 # Beacon: 'bt_'
    checksum: true                       # Q-O3
    hash: { version: 1, pepper: null }   # 2 = HMAC with '%env(NOWO_API_TOKEN_PEPPER)%' (Q-O3)
    doctrine: { entity_manager: default, table_prefix: 'nowo_', auto_mapping: true, mapping: xml }   # mapping: xml | attribute (§5.1.1)
    owner: { type: user, class: null, id_field: id, resolver: null, status: null, user_checker: null }   # user_checker: null (firewall checkers) | service id | 'none'
    modules:                             # merged with bridge-provided modules
        # reports: { levels: [read], flags: [], label: 'Reports' }
    bridges: { task_board: auto, wiki: auto, mcp_kit: auto, time_track: false, http_log: auto, user_kit: auto, audit_kit: auto, auth_kit: auto }
    reach: { allow_everything: true, provider: null, max_entries: 200 }
    expiry: { default_days: 90, max_days: 365, choices: [7, 30, 90, 365], purge_after_days: 30, notify_before_days: null }
    max_per_owner: 20
    reauth: { enabled: true, fresh_seconds: 300, max_attempts: 5, gate: null }
    rate_limit:
        cache_pool: cache.rate_limiter
        per_token: { limit: 100, interval: '1 minute' }
        writes: { limit: 30, interval: '1 minute' }
        count_rest_writes: false
        failed_per_ip: { limit: 20, interval: '15 minutes' }
        issue_per_owner: { limit: 10, interval: '1 hour' }
    firewall: { on_failure: pass }       # pass | unauthorized
    legacy: { beacon_v1: false }         # §9.1 dual-read of migrated Beacon tokens
    http_log: { erase_usage_on_revoke: false }   # §6.9, Q-B6
    schedule: { enabled: false, purge_expired: '20 3 * * *', notify_expiring: '0 8 * * *' }   # §10.1
    messenger: { max_seconds: 50, batch_size: 500 }
    revoke_on_sessions_revoked: []       # UserKit RevocationReason values
    api:                                 # §7.1
        enabled: false
        prefix: '/api'                   # container parameter %nowo_api_token.api_prefix%
        revocation_endpoint: false       # Q-O9
    web_ui:
        enabled: false
        path_prefix: '/account/security/api-tokens'
        layout_template: null
        templates: { list: null, created: null }
    admin_ui:
        enabled: false
        path_prefix: '/admin/api-tokens'
        security: { access_roles: ['ROLE_ADMIN'], access_checker: null, allow_unauthenticated: false }
    settings_provider: null              # rule 8; InstanceSettings bridge optional
```

The tree is strict (REQ-SF-006). Error messages name the path and the allowed values. `enabled: false` registers no
route, no listener and no authenticator service in any firewall.

### 7.1 HTTP API (versioned, public)

Canonical `<prefix>/tokens/v1/…` (rule "Public HTTP API paths"; segment `tokens`; the prefix is the container
parameter `%nowo_api_token.api_prefix%`, so `security.yaml` patterns derive from it). Bearer only, stateless, JSON,
`Cache-Control: no-store`, refusals `{"err", "ECODE"}` like TaskBoard and Wiki.

| Method / path | Answer | Notes |
| --- | --- | --- |
| `GET <prefix>/tokens/v1/self` | `200 {id, lookup_id, name, modules: {tasks: "read", mcp: {level: "on", flags: ["destructive"]}}, reach: {everything: bool, count: int}, created_at, expires_at}` | No owner e-mail, no reach entries (they can name tenants): introspection for scripts and agents |
| `DELETE <prefix>/tokens/v1/self` | `204` | Self-revoke (reason `self_api`) |
| `POST <prefix>/tokens/v1/revocations` | `202` always | Body `{"token": "<full token>"}`; revokes when valid (reason `compromised`); same answer otherwise; failed-IP limiter applies. For leak reporting (secret scanning partners, Q-O9); off by default (`api.revocation_endpoint: false`) |

OpenAPI 3.1 `docs/openapi/tokens-v1.yaml` with a test that compares it with the routes (as `ApiOpenApiTest` in
TaskBoard and Wiki). No unversioned aliases are needed: these paths are new.

Internal UI routes (`web_ui`, `admin_ui`) are not under the API prefix and not versioned (rule 5).

## 8. UI (UiKit + FormKit, REQ-UI-001/003, REQ-TWIG-005)

### 8.1 Account → Security → API tokens

The page uses a UiKit `ListPage` scaffold with a settings intro. Its contents:

- **Base URLs:** what each enabled module's base URL is, read from bridge metadata (`/api/tasks/v2`, `/api/wiki/v1`,
  `/mcp`), built from the request host for display only.
- **Token table:** name, lookup id `bt_k7m2…`, modules and levels as chips, the *agents may delete* badge, reach
  (*everything* or N places), created, last used (relative time), expires (with a warning chip within 7 days), and a
  row action *Revoke* (confirm dialog, FormKit CSRF form).
- **Revoke all:** a header action, CSRF, behind the re-auth step.
- **New token** dialog (UiKit `_remote_form_dialog`, wide):
  - re-auth step;
  - name;
  - one select per module (*None* plus its levels), each with its help text;
  - *Agents may delete and archive* tick under MCP;
  - reach: *everything* or chosen places, with a searchable checklist and groups from `ReachChoice::$group`;
  - expiry choice.
- **After create:** the response re-renders the page with `_open_modal_on_load` showing the secret once. The dialog
  uses `temporary-reveal` (clearOnHide), `clipboard-copy`, the text "This is the only time you will see it", and
  example `curl` lines with a `$TOKEN` placeholder (never the secret pasted into the example).
- The empty state explains what a token is.
- Every template can be overridden (`web_ui.templates.*`, REQ-TWIG-001). `docs/TEMPLATES.md` documents them. A
  `PageContractTestCase` subclass tests the page.

### 8.2 Operator page

`admin_ui.path_prefix`: keyset-paginated table (owner, name, lookup id, modules, created, expires, last used), a
filter by owner key and by lookup id (an operator can find a token reported as leaked from its visible prefix), and
*Revoke*. There is no *issue on behalf* in v1.

### 8.3 Translations

There are seven locales with key parity (REQ-I18N-002), in the domain `NowoApiTokenBundle`. Module labels and level
help texts come from the bridges' own keys, with the bundle's defaults.

### 8.4 Personal data (`REQ-DATA-001…004`)

- **Eraser (UserKit bridge).**
  - `ApiTokensEraser` (`getKey(): 'api_tokens'`) runs `revokeAll(owner, Erasure)`, which also covers the
    idempotency forget.
  - It is keyed by owner id, so it can run in any order relative to the identity eraser (`REQ-DATA-002`). Its
    priority is 63, Beacon's current one.
  - It does not flush: it runs DBAL statements inside the erasure transaction, on the same connection.
- **Export provider.** `ApiTokensExportProvider` (`getSectionKey(): 'api_tokens'`) exports id, lookup id, name,
  modules and levels, flags, reach entries, created, expires and last used. It never exports the hash. There is no
  cap: the owner has at most `max_per_owner` rows.

## 9. Beacon migration (what Beacon deletes)

### 9.1 Data

1. Migration: create `nowo_api_token` (or the prefix Beacon chooses), then copy every `task_api_token` row:
   | Beacon column | Bundle column |
   | --- | --- |
   | `uuid` | `id` |
   | `user_id` | `owner_id`, with `owner_type` = `user` |
   | `name`, `created_at`, `expires_at`, `last_used_at` | the same columns |
   | `level` | `grants.levels.tasks` |
   | `wiki_level` | `grants.levels.wiki` |
   | `mcp_destructive` | `grants.flags.mcp: [destructive]` |
   | `reach_everything`, `tenant_keys` + `board_ids` | `reach` |
   | `token_hash` | `secret_hash` with `hash_version 0` |
   - `lookup_id` is a fresh random id; Beacon v1 secrets do not contain it.
   - The `mcp` level is `on` when MCP is enabled for the instance. Beacon's MCP gate today is the API switch plus
     `MCP_ENABLED`, not per token, so every migrated token gets `mcp: on`; this keeps behaviour.
2. **Legacy verification path** (`legacy.beacon_v1: true`): a presented `bt_<43>` token without a lookup id is
   looked up by `secret_hash` among `hash_version 0` rows (unique index on `secret_hash` for those rows only, a
   partial index on PostgreSQL and a nullable unique column `legacy_hash` on MySQL), then `hash_equals`. Legacy
   tokens keep working until they expire (≤ 365 days). New tokens use the v2 format. The path and its column go in
   the next major after the last legacy token expired; `nowo:api-token:legacy-report` counts the rows left
   (`REQ-ENCRYPT-002` dual-read pattern).
3. Drop `task_api_token` once the copy is verified (a second migration, after one release).

### 9.2 Code Beacon deletes (≈ 1,600 lines)

| Path | Lines | Replaced by |
| --- | --- | --- |
| `src/Tasks/Entity/TaskApiToken.php` | 183 | `Domain\ApiToken` + XML |
| `src/Tasks/Repository/TaskApiTokenRepository.php` | 38 | `DbalApiTokenStore` |
| `src/Tasks/Api/TaskApiTokens.php` | 168 | Issuer, revoker, authenticator |
| `src/Tasks/Api/TaskApiAppTokenSource.php` | 52 | `Bridge\Host\AppTokenSourceAdapter` (Beacon keeps a 15-line adapter to its own `AppTokenSource` interface) |
| `src/Tasks/Api/Security/TaskApiFirewallAuthenticator.php` | 62 | `ApiTokenFirewallAuthenticator` |
| `src/Tasks/Api/Hook/BeaconTaskApiAuthenticator.php`, `BeaconTaskApiRateLimiter.php` | 99 | TaskBoard bridge |
| `src/Wiki/Api/BeaconWikiApiAuthenticator.php`, `BeaconWikiApiRateLimiter.php` | 104 | Wiki bridge |
| `src/Mcp/Security/BeaconMcpAuthenticator.php`, `BeaconMcpRateLimiter.php` | 128 | McpKit bridge (`BeaconMcpContext` stays if the tools need it; Q-B3) |
| `src/Tasks/Form/TaskApiTokenType.php` | 126 | Bundle form |
| `src/Tasks/Controller/AccountTaskApiTokensController.php` | 176 | Account page |
| `templates/account/security_task_api.html.twig` | 111 | Bundle template (Beacon may override) |
| `src/Tasks/PersonalData/TaskApiTokensEraser.php` | 42 | UserKit bridge |
| `src/Shared/Http/ApiCredential.php` | 53 | Kept for `read_token` only, or removed if Beacon moves read tokens to its own stamp (Q-B6); the personal-token stamp moves to the bundle |
| `config/packages/rate_limiter.yaml` `task_api*` limiters | — | Bundle config |
| `TaskApiSettings` | 37 | `ApiTokenSettingsProviderInterface` (Beacon keeps `TASK_API_ENABLED` / `MCP_ENABLED` env through a 20-line provider) |

Beacon keeps:

- `TaskApiOrganizations` (135), as its `ReachFilterInterface` + `ReachProviderInterface`;
- `BeaconTaskApiWorkspace::reachableTenants()` and `BeaconWikiApiDirectory::namedPlaces()`, as the reach choices;
- every non-auth hook (workspace, users, time entries, attachments, directory, files, management, plan gates);
- the write-audit listeners.

`security.yaml` swaps `TaskApiFirewallAuthenticator` for the bundle's authenticator on the `task_api` and `mcp`
firewalls, whose patterns move to `%nowo_api_token.api_prefix%`.

## 10. Worker-mode design (FrankenPHP, kernel reset false)

- Every service is `final readonly` and stateless. The principal lives only on `Request::$attributes`.
- The module registry is compiled into a container parameter: immutable, the same for every request.
- No memo of tokens, owners, reach or status. Each request reads its row (one indexed query). The account status is
  re-checked on each request.
- No managed entities (§5.4). DBAL transactions open and close inside each call, and a dropped connection is
  retried once. The after-commit event buffer implements `ResetInterface`, is cleared on `kernel.terminate`,
  `kernel.finish_request` and `WorkerMessageHandledEvent`, and has a test of each path.
- Rate limiters live in the cache pool. There are no file handles and no locks.
- **Tests:**
  - one kernel, `disableReboot()`, two owners and three tokens alternating over 2,000 requests (valid, wrong secret,
    expired, revoked mid-run);
  - assert each request sees only its own principal, the refused ones carry no attribute, and memory does not grow
    (≤ 1 MiB drift);
  - Igor and `phpstan-frankenphp` clean.
- README states worker support (REQ-DEMO-008, REQ-DOCS-017).

### 10.1 Messenger and scheduled work (`REQ-MESSENGER-007/008/009`)

- The request path never touches Messenger: authentication, issue and revoke are synchronous and small.
- Maintenance is **enqueue-only** from the scheduler (`REQ-MESSENGER-008`): the bundle ships a Symfony Scheduler
  recipe (`nowo_api_token.schedule: {enabled: false, purge_expired: '20 3 * * *', notify_expiring: '0 8 * * *'}`)
  that dispatches `PurgeExpiredApiTokensMessage` and `NotifyExpiringApiTokensMessage`; hosts with their own
  maintenance schedule (Beacon `MaintenanceSchedule`) call the commands instead, which also only enqueue (from S5;
  `--sync` runs them directly). Until S5 (0.1.0 … 0.4.x) `nowo:api-token:purge-expired` purges directly (Q3).
- Handlers work in keyset batches of 500 (`REQ-MESSENGER-007`), stop after `messenger.max_seconds` (default 50 s,
  below the worker stop grace, `REQ-MESSENGER-009`) and re-dispatch themselves for the rest.
- `ApiTokenExpiringEvent` is dispatched by the handler after its read; the NotifyKit bridge (contract 221-B) turns it
  into a bell row and an e-mail, deduplicated per token and expiry date.
- Routing: the messages implement a marker interface `ApiTokenMaintenanceMessage`; the docs route it to the host's
  maintenance transport. With no transport the messages run synchronously (fine for small installs).
- The after-commit buffer and every handler clear their state on `WorkerMessageHandledEvent` (§10).

## 11. Test plan (coverage gate ≥ 99 % PHP, ≥ 90 % TS)

- **Format:** the parser fuzz table (length, charset, checksum, prefix case, Unicode look-alikes, NUL, a trailing
  newline) gives `Malformed` with no DB query (query counter at 0).
- **Timing guard:** `Unknown` and `WrongSecret` both call `hash_equals` (spy), and no SQL compares the hash.
- **Status:** expired, owner disabled (user checker), owner erased (resolver null), module switch off, revoked
  between two requests.
- **Grants:** an unknown module or level in stored JSON is dropped; a bridge refuses an unknown enum value; the MCP
  destructive flag is off by default and needs its own tick; grants never widen through a level rename.
- **Reach:** chosen ⊄ choices refused; an empty list reaches nothing; the filter chain only narrows; mapping to
  `TaskApiWhere` / `WikiApiWhere`.
- **Collation (MySQL, CI service):** `owner_id` `usera` vs `UserA` gives two owners, lists and erasure do not cross,
  and the PHP `===` re-check is covered by a mocked store returning a wrong-case row.
- **Rate limits:** one bucket across TaskBoard, Wiki and MCP; write sub-limit; failed-IP peek before parsing.
- **Re-auth:** issue without fresh re-auth refused; freshness bound to the session (a second session refused); 5
  attempts cap; framing and `no-store` headers present on GET and POST; the secret appears exactly once in the POST
  response and never in the session, flash, logs or events (grep of the test log and the event payloads).
- **UI:** `PageContractTestCase`; anonymous gives 302/401; another user's token id gives 404 on revoke; CSRF missing
  gives 403; the operator page gives 403 without a role.
- **API:** `self` GET and DELETE; revocation endpoint answers 202 in every case; OpenAPI matches the routes.
- **Personal data:** eraser and export cover the same keys (`REQ-DATA-004`), and no hash is exported.
- **Both host kinds:** a standard fixture app (attribute `User` entity, Doctrine resolver, default EM) and a DDD
  fixture app (XML-mapped `User` on a second EM, custom `OwnerResolverInterface`, `auto_mapping: false`) run the same
  functional suite.
- **Worker:** §10.
- **Demo:** Symfony 8.1 + FrankenPHP worker + MySQL. The demo issues a token through the page (Playwright, WebP
  screenshots, REQ-DEMO-013) and calls a demo host API and `GET /api/tokens/v1/self` with it.

## 12. Slices

Every slice is a PR on `nowo-tech/ApiTokenBundle`. Slices S1 to S4 are **authentication slices**, so under the
2026-11-07 batch-audit rule each needs an **immediate SEC-004 audit** before any tag that contains it.

| Slice | Version | Content | Done when | Audit |
| --- | --- | --- | --- | --- |
| **S0** scaffold | `main`, no tag | Composer, Docker, Makefile + release-check, CI matrix (SF 7.4 / 8.0 / 8.1, MySQL + PostgreSQL + SQLite), PHPStan ≥ 8 + `phpstan-frankenphp`, Igor, Rector, CS, coverage gate, Spec Kit baseline, docs set, `.github` templates, release / sync workflows, Flex recipe, demo skeleton (FrankenPHP worker, MySQL), `docs/SECURITY.md` threat model, empty bundle class, strict config tree with `enabled: false` | `make release-check` green; REQ checklist for the scaffold 100 %. **Then the owner registers the package on Packagist** | Batched (no runtime code) |
| **S1** core | 0.1.0 | Domain + XML mapping + ID mode + table prefix, `DbalApiTokenStore`, format / hash / checksum, issuer, revoker, query, authenticator service (§6.2), module registry, `Grants`, `Reach`, owner resolver / status defaults, events after commit, purge command, CLI `nowo:api-token:issue` (for tests and break-glass, prints once) | §11 format, status, grants, collation, worker tests; both host kinds | **Immediate** |
| **S2** firewall + limits | 0.2.0 | `ApiTokenFirewallAuthenticator`, request stamp, `on_failure`, stateless check, rate limiters, failed-IP limiter, `ReachFilterInterface` chain, host-API integration docs | Firewall functional tests; one bucket; timing guard | **Immediate** |
| **S3** account + operator UI | 0.3.0 | Account page, *New token* dialog, re-auth gate + framing headers, revoke / revoke all, operator page, translations ×7, Stimulus wiring (UiKit controllers only, no own TS unless needed), templates doc | UI tests, Playwright e2e, page contract | **Immediate** (credential-granting UI, `REQ-AUTH-013`) |
| **S4** bridges | 0.4.0 | TaskBoard, Wiki, McpKit bridges (authenticators, limiters, idempotency forget, module definitions), HttpLog resolver + usage reader, UserKit eraser + export + sessions listener, AuditKit recorder, AuthKit re-auth adapter (if Q-O4 yes), AppTokenSource adapter | Each bridge tested with the real bundle in require-dev; Beacon-shaped fixture (three modules, one token) | **Immediate** |
| **S5** public API + 1.0 | 0.5.0 → **1.0.0** | `<prefix>/tokens/v1/self` GET/DELETE, optional revocation endpoint, OpenAPI, legacy Beacon v1 path (§9.1) and `legacy-report` command, `ApiTokenExpiringEvent` + command, `PurgeExpiredApiTokensMessage` + handler + Scheduler recipe (the purge command enqueues by default, `--sync` for direct runs), docs (USAGE, CONFIGURATION, UPGRADING, FRANKENPHP-WORKER-AUDIT, TEMPLATES), `bundle-req-audit` 100 % | REQ audit 100 %, SEC-004 audit Pass on S0–S5, owner OK | **Immediate** (new endpoints); then 1.0.0 |
| Beacon | after 1.0.0 (or 0.4.0 behind a flag, Q-O6) | Migration §9, `security.yaml`, delete §9.2 | Beacon's SC matrix (213 / 218 / 219) green on the bundle | Beacon's own |

TimeTrack bridge (Q-O8) and organisation/project tokens (Q-O7) are 1.x minors.

## 13. Questions

### For the owner (Q-O)

| # | Question | Recommendation | If not |
| --- | --- | --- | --- |
| Q-O1 | A new bundle `nowo-tech/api-token-bundle` (repo `ApiTokenBundle`), or an `api_tokens` feature of AuthKitBundle? | **New bundle.** API-only hosts do not need AuthKit's sign-in stack. The auth audit stays small and does not block AuthKit releases. Re-auth with MFA comes through an optional AuthKit adapter | AuthKit 1.44 feature `api_tokens`, same design, with `Nowo\AuthKitBundle\ApiToken\*` namespaces |
| Q-O2 | Row id: UUIDv7 `string(36)` with binary collation (house style) or `binary(16)`? | **`string(36)`**, the same as TaskBoard / Wiki / TimeTrack, readable in exports and logs | `binary(16)` with a Doctrine `uuid_binary` type, which is smaller but needs a custom type on DDD hosts |
| Q-O3 | Token format and hash: `prefix + lookup id + _ + secret + CRC32 checksum`, SHA-256 at rest, no pepper? | **Yes to the lookup id and checksum, SHA-256 without pepper.** 256-bit secrets make a pepper unnecessary, and a pepper ties every token to one env value (rotating it kills all tokens). The pepper stays available as `hash.version: 2` for hosts that want it | No checksum (shorter token, no offline typo check, weaker secret scanning); or a mandatory pepper |
| Q-O4 | Re-auth before issue (`REQ-AUTH-013`): may the bundle ship an AuthKit adapter, and should AuthKit gain a small public `StepUpInterface` (password or MFA, session-bound freshness) that ApiTokenBundle, TimeTrack app passwords and device approval can all use? | **Yes**, an AuthKit minor with `StepUpInterface`. Until it exists, the bundle's `PasswordReauthGate` covers password accounts | Each bundle keeps its own password re-entry |
| Q-O5 | Tokens always expire (max 365 days), with no "never expires" option? | **Yes** (Beacon D-20261106-14). Hosts can lower the maximum, but not lift it | `expiry.allow_never: true` as an opt-in, with a warning chip in the list |
| Q-O6 | Tag 0.x per slice (0.1.0 … 0.5.0) so Beacon can integrate early, or only 1.0.0 at the end, as McpKit did? | **0.x per slice, after each immediate audit**; Beacon adopts only 1.0.0 in production. The 100 % REQ bar applies at 1.0.0 | Single 1.0.0 tag after S5 |
| Q-O7 | Should v2 add organisation / project tokens (machine credentials not tied to a person) on the same store? | **Not in 1.x.** Beacon's ingest and read keys have different lifecycles (rotation grace, HKDF). Revisit when a second app needs them | Plan an `owner_type: organization` path in 1.x |
| Q-O8 | TimeTrack: its client API uses short-lived tokens issued from an app password (device flow). Should a personal token also authenticate the TimeTrack client API (module `time_track`)? | **Not in 1.0.** Desktop and Extension use the device flow, which stays. Add the module in a 1.x minor if an integration asks | Bridge in S4 |
| Q-O9 | Public revocation endpoint (`POST <prefix>/tokens/v1/revocations`, possession revokes), for leak reports and secret scanning partners? | **Ship it off by default** in S5. GitHub's secret-scanning partner programme needs a public endpoint per app, which is each app's choice | Leave it out |
| Q-O10 | Bridges inside ApiTokenBundle (TaskBoard, Wiki, McpKit, HttpLog, UserKit, AuditKit, AuthKit) or adapters inside each of those bundles, as Beacon's request suggests? | **Inside ApiTokenBundle**, activated when the class exists. The auth code then lives in one audited place, and TaskBoard / Wiki / McpKit stay unchanged ("the bundle stores no tokens" remains true) | Each bundle ships an adapter: six repos to change, each needing an auth audit |
| Q-O11 | `REQ-ORM-001` rule 1 says "mapping via PHP 8 attributes"; the DDD bar wants an attribute-free domain class. Does a bundle-owned, ID-referenced entity with **XML mapping by default plus an attribute-mapped twin** (`doctrine.mapping: xml\|attribute`, same schema, tested) count as compliant, for this bundle and for NotifyKit / ReportKit? | **Yes**, and amend `REQ-ORM-001` rule 1 to "attributes or XML; never annotations". The audit then has one rule for the three new bundles | Attribute mapping only (DDD hosts reference the rows by id and never load the class), and the XML file is dropped |
| Q-O12 | Shared value objects: `OwnerRef` (this bundle), `RecipientRef` (NotifyKit) and the report owner (ReportKit) are the same idea. A tiny `nowo-tech/identity-contracts` package (like `brand-contracts`), or a copy per bundle? | **A copy per bundle in 1.0** (three `final readonly` classes of ~30 lines, no coupling, no extra release train); revisit if a fourth bundle needs it | New contracts package first, which delays S1 of all three |

### For Beacon (Q-B)

| # | Question | Recommendation |
| --- | --- | --- |
| Q-B1 | Prefix and format: keep `bt_` for new tokens in the v2 format (`bt_<lookup>_<secret><checksum>`), with legacy `bt_<43>` tokens accepted until they expire? | Yes. Users keep their tokens. The docs show the new shape |
| Q-B2 | MCP per token: today MCP is an instance switch (`MCP_ENABLED`) and every token can use it. With the bundle, MCP becomes a module a token must be granted (`mcp: on`). Migrate every existing token with `mcp: on`, or with no MCP grant (people tick it again)? | `mcp: on` for migrated tokens (no behaviour change); new tokens default to no MCP grant |
| Q-B3 | `BeaconMcpContext` (`hostContext` with the Task and Wiki principals, reach flags, expiry): does the McpKit bridge's `hostContext` (an `ApiTokenMcpContext` with `TaskApiPrincipal`, `WikiApiPrincipal`, `ApiTokenPrincipal`) replace it, or do your tools need more? | Use the bridge's context. Tell us what else your tools read |
| Q-B4 | Re-auth (`REQ-AUTH-013`) is not on the token page today. Do you accept that creating a token and *Revoke all* now ask for the password (or MFA through AuthKit), with a 5-minute freshness window? | Yes. It also closes the gap the REQ lists |
| Q-B5 | Audit rows: keep your `UserActionType::TaskApiTokenCreated/Revoked` through a listener on the bundle's events, or use the AuditKit bridge's generic `api_token.*` actions? | Keep your enum through a 30-line listener, so your audit page does not change |
| Q-B6 | `ApiCredential` also carries `read_token` (project read API). Keep the class for read tokens only, with the bundle's HttpLog resolver returning `personal_token`; and should revoking a token erase its usage counters? | Keep `ApiCredential` for `read_token`. Do not erase the counters on revoke (they hold no personal content); erase them on person erasure only |
| Q-B7 | `revoke_on_sessions_revoked`: today sign-out everywhere keeps tokens, and *Revoke all* and "it wasn't me" end them. Should `account_disabled` also delete them, or only refuse them while disabled (re-enabling restores them)? | Only refuse (no row change): re-enabling the account should bring the person's integrations back without new tokens |
| Q-B8 | Table name: `nowo_api_token`, or keep a Beacon name through `table_prefix`? | `nowo_api_token`, and drop `task_api_token` one release after the copy |

## Review notes v0.2 (2026-11-11, after the account switch)

The v0.1 draft was complete in substance. This pass only:

1. Fixed a cross-reference (failed-IP limiter is §6.7, not §6.5).
2. Added the `legacy_hash` column that §9.1 needs, and the config keys that the text used but §7 did not list
   (`legacy.beacon_v1`, `api.revocation_endpoint`, `http_log.erase_usage_on_revoke`, `schedule`, `messenger`,
   `doctrine.mapping`).
3. Added §5.1.1 (attribute vs XML mapping and `REQ-ORM-001`), §5.3 (schema and migrations, `REQ-ORM-001` rule 5) and
   §10.1 (Messenger and scheduled work, `REQ-MESSENGER-007/008/009`), which the "New bundles" bar requires.
4. Added Q-O11 (mapping rule) and Q-O12 (shared owner VO), which also apply to contracts 221-B and 221-C.
