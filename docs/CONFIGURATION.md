# Configuration

Root key: `nowo_api_token`. The tree is **strict** (REQ-SF-006): unknown keys and invalid values are refused at
container build, and the message names the path and the allowed values. Every key of contract §7 is accepted from S0;
the column *Used from* says which slice reads it. With `enabled: false` (the default) nothing is registered, whatever
the other values.

`enabled: true` (S1) needs DoctrineBundle and an owner: `owner.class` (your user entity, for the default Doctrine
resolver) or `owner.resolver` (an `OwnerResolverInterface` service); the container refuses to build otherwise, with a
message that says so. It also needs a real account-status check: see [Owner status](#owner-status).

## Table of contents

- [Full reference](#full-reference)
- [Owner status](#owner-status)
- [Changes that invalidate every token](#changes-that-invalidate-every-token)
- [Container parameters](#container-parameters)
- [Translations](#translations)
- [Validation rules](#validation-rules)

## Full reference

```yaml
nowo_api_token:
    enabled: false                       # nothing reachable while false
    token_prefix: 'nwt_'                 # ^[a-z]{2,8}_$ ; Beacon sets 'bt_'
    checksum: true                       # CRC32 base62 suffix (Q-O3)
    hash: { version: 1, pepper: null }   # 2 = HMAC-SHA256, pepper required (read it from an environment variable)
    doctrine: { entity_manager: default, table_prefix: 'nowo_', auto_mapping: true, mapping: xml }   # mapping: xml | attribute
    owner: { type: user, class: null, id_field: id, resolver: null, status: null, user_checker: null }   # user_checker: null (auto) | service id | 'none'
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
    legacy: { beacon_v1: false }         # dual-read of migrated Beacon v1 tokens
    http_log: { erase_usage_on_revoke: false }
    schedule: { enabled: false, purge_expired: '20 3 * * *', notify_expiring: '0 8 * * *' }
    messenger: { max_seconds: 50, batch_size: 500 }
    revoke_on_sessions_revoked: []       # UserKit RevocationReason values
    api:
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
    settings_provider: null              # ApiTokenSettingsProviderInterface service (rule 8)
```

| Key | Used from |
| --- | --- |
| `enabled`, `doctrine`, `owner`, `modules`, `token_prefix`, `checksum`, `hash`, `expiry` (except `choices`, `notify_before_days`), `max_per_owner`, `reach`, `settings_provider`, `messenger` (the purge command) | S1 |
| `firewall`, `rate_limit` | S2 |
| `reauth`, `web_ui`, `admin_ui`, `expiry.choices` | S3 |
| `bridges`, `http_log`, `revoke_on_sessions_revoked` | S4 |
| `api`, `legacy`, `schedule`, `expiry.notify_before_days` | S5 |

S1 service ids (all public aliases a host may replace through the keys above or by decorating them):
`ApiTokenIssuerInterface`, `ApiTokenRevokerInterface`, `ApiTokenQueryInterface`, `ApiTokenAuthenticatorInterface`,
`OwnerResolverInterface` (`owner.resolver`), `OwnerStatusInterface` (`owner.status`), `ReachProviderInterface`
(`reach.provider`), `ApiTokenPolicyInterface`, `ApiTokenSettingsProviderInterface` (`settings_provider`), all in
`Nowo\ApiTokenBundle\Contract`. Services implementing `TokenRevocationListenerInterface` or
`ModuleDefinitionProviderInterface` are tagged automatically (autoconfiguration).

## Owner status

Every authentication (and every issue) asks whether the token's owner is still active. The default
`UserCheckerOwnerStatus` runs a Symfony user checker's `checkPreAuth()` (an `AccountStatusException` means inactive):

| `owner.status` | `owner.user_checker` | Checker used |
| --- | --- | --- |
| null | null (default) | **Auto:** the user checker of every firewall (`security.user_checker.<firewall>`), chained, so your own checker and UserKit's `AccountStatusUserChecker` are consulted. The container refuses to build when no firewall has a checker other than Symfony's stock `security.user_checker` (`InMemoryUserChecker`, which checks nothing for entity users) |
| null | a service id | That `UserCheckerInterface` service only (e.g. `App\Security\UserChecker`, or `security.user_checker.chain.api`) |
| null | `none` | No checker: an owner the resolver finds is active. Explicit opt-out for hosts without account states |
| a service id | (ignored) | Your `OwnerStatusInterface` (DDD hosts) |

With a checker, a user object that is not a Symfony `UserInterface` is **inactive** (fail closed). The default Doctrine
resolver re-reads the user from the database on every call, so a deactivation by another process takes effect on the
next request.

## Changes that invalidate every token

There is no key rotation in 0.x. Each of these changes makes **every existing token** fail at once (their stored hash
or their prefix no longer matches), so plan it as a forced re-issue and warn your users first:

- changing `hash.pepper`;
- going from `hash.version: 2` back to `1` (or removing the pepper);
- changing `token_prefix`.

Switching from `hash.version: 1` to `2` keeps the existing version-1 tokens working (each row stores its version);
only new tokens use HMAC.

## Container parameters

| Parameter | Value |
| --- | --- |
| `nowo_api_token.enabled` | `enabled` |
| `nowo_api_token.api_prefix` | `api.prefix`: derive firewall patterns from it, e.g. `^%nowo_api_token.api_prefix%/(tasks\|wiki\|tokens)/` |
| `nowo_api_token.table_prefix` | `doctrine.table_prefix` |
| `nowo_api_token.config` | the processed tree, with `hash.pepper` redacted (the pepper stays only in the hasher service) |

## Translations

The bundle's UI strings (S3 pages) use the translation domain `NowoApiTokenBundle` and ship in **seven locales**: `en`,
`es`, `it`, `fr`, `pt`, `de`, `nl` (`src/Resources/translations/NowoApiTokenBundle.<locale>.yaml`, key parity checked by
`make validate-translations`). To override a string, put the same key in your application's
`translations/NowoApiTokenBundle.<locale>.yaml`; application translations win over bundle ones. To add a locale, create
that file in your application with every key of the English catalogue.

## Validation rules

- `token_prefix`: 2 to 8 lower-case letters and `_`.
- `hash.version: 2` needs a non-empty `hash.pepper`.
- `expiry.max_days` ≤ 365 (tokens always expire, Q-O5); `default_days` and every `choices` entry ≤ `max_days`;
  `notify_before_days` null or 1 to 365.
- `modules`: keys, levels and flags match `^[a-z][a-z0-9_]{0,31}$`; at least one level; no duplicate level.
- `owner.type`: `^[a-z][a-z0-9_]{0,31}$`.
- `doctrine.table_prefix`: lower-case letters, digits and `_`, at most 16 characters, may be empty.
- Tri-state switches (`bridges.*`): `true`, `false` or `"auto"` (on when the integration is installed).
- Paths (`api.prefix`, `web_ui.path_prefix`, `admin_ui.path_prefix`): absolute, no trailing slash.
- Rate-limit intervals: `<n> second|minute|hour|day[s]`; schedules: five-field cron expressions.
- Service ids (`owner.resolver`, `owner.status`, `reauth.gate`, …): null or a non-empty string; `owner.user_checker`:
  null, `none` or a service id.
