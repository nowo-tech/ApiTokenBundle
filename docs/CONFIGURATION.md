# Configuration

Root key: `nowo_api_token`. The tree is **strict** (REQ-SF-006): unknown keys and invalid values are refused at
container build, and the message names the path and the allowed values. Every key of contract §7 is accepted from S0;
the column *Used from* says which slice reads it. **S0 registers nothing**, whatever the values.

## Table of contents

- [Full reference](#full-reference)
- [Container parameters](#container-parameters)
- [Validation rules](#validation-rules)

## Full reference

```yaml
nowo_api_token:
    enabled: false                       # nothing reachable while false
    token_prefix: 'nwt_'                 # ^[a-z]{2,8}_$ ; Beacon sets 'bt_'
    checksum: true                       # CRC32 base62 suffix (Q-O3)
    hash: { version: 1, pepper: null }   # 2 = HMAC-SHA256, pepper required (read it from an environment variable)
    doctrine: { entity_manager: default, table_prefix: 'nowo_', auto_mapping: true, mapping: xml }   # mapping: xml | attribute
    owner: { type: user, class: null, id_field: id, resolver: null, status: null }
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
| `enabled`, `doctrine`, `owner`, `modules`, `token_prefix`, `checksum`, `hash`, `expiry`, `max_per_owner`, `schedule`, `messenger` | S1 |
| `firewall`, `rate_limit`, `reach` | S2 |
| `reauth`, `web_ui`, `admin_ui` | S3 |
| `bridges`, `http_log`, `revoke_on_sessions_revoked`, `settings_provider` | S4 |
| `api`, `legacy` | S5 |

## Container parameters

| Parameter | Value |
| --- | --- |
| `nowo_api_token.enabled` | `enabled` |
| `nowo_api_token.api_prefix` | `api.prefix`: derive firewall patterns from it, e.g. `^%nowo_api_token.api_prefix%/(tasks\|wiki\|tokens)/` |
| `nowo_api_token.table_prefix` | `doctrine.table_prefix` |
| `nowo_api_token.config` | the processed tree |

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
- Service ids (`owner.resolver`, `reauth.gate`, …): null or a non-empty string.
