# Installation

## Table of contents

- [Requirements](#requirements)
- [Install](#install)
- [Enable the bundle](#enable-the-bundle)
- [Database](#database)
- [Next steps](#next-steps)

## Requirements

- PHP 8.2 to 8.5 (Symfony 8.x needs PHP 8.4+).
- Symfony 7.4, 8.0 or 8.1 (CI covers 7.4 and 8.1).
- DoctrineBundle with the ORM (`doctrine/orm` ^3.3, `doctrine/dbal` ^4) when the bundle is enabled.
- The package is built slice by slice (S1 core today, no tag yet): see [ROADMAP.md](ROADMAP.md) before you depend on it.

## Install

```bash
composer require nowo-tech/api-token-bundle
```

With Symfony Flex the recipe registers the bundle and copies `config/packages/nowo_api_token.yaml` with
`enabled: false`. Without Flex, register it yourself:

```php
// config/bundles.php
return [
    // ...
    Nowo\ApiTokenBundle\NowoApiTokenBundle::class => ['all' => true],
];
```

## Enable the bundle

Nothing is registered while `nowo_api_token.enabled` is false (the default). With `enabled: true` and an owner
(`owner.class` or `owner.resolver`) the S1 core services, the Doctrine mapping and the two console commands are
registered ([USAGE.md](USAGE.md)). These container parameters always exist:

| Parameter | Value |
| --- | --- |
| `%nowo_api_token.enabled%` | `enabled` |
| `%nowo_api_token.api_prefix%` | `api.prefix` (default `/api`): use it in `security.yaml` patterns so they follow one setting |
| `%nowo_api_token.table_prefix%` | `doctrine.table_prefix` (default `nowo_`) |
| `%nowo_api_token.config%` | the processed configuration |

See [CONFIGURATION.md](CONFIGURATION.md) for every key.

## Database

The bundle owns its tables and maps them in XML by default (plain domain classes, referenced by id). It ships **no
Doctrine migration classes**: standard hosts run `php bin/console doctrine:migrations:diff` after an upgrade that adds
or changes a table, and check the result against the reference DDL below.

Identifier columns (owner, recipient, actor ids) use a binary collation (`utf8mb4_bin`) on MySQL / MariaDB, so ids
compare exactly; PostgreSQL and SQLite compare exactly by default.

DDD hosts set `doctrine.auto_mapping: false` and register the bundle's XML mapping on their own entity manager (see
[USAGE.md](USAGE.md#using-it-from-a-ddd--hexagonal-application)).

### Reference DDL (`doctrine.table_prefix: nowo_`)

MySQL 8 / MariaDB 11 (binary collations on the identifiers, audit LQ):

```sql
CREATE TABLE nowo_api_token (lookup_id VARCHAR(12) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, secret_hash VARCHAR(64) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, hash_version SMALLINT NOT NULL, owner_type VARCHAR(32) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, owner_id VARCHAR(128) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_bin`, name VARCHAR(80) NOT NULL, grants JSON NOT NULL, reach_everything TINYINT NOT NULL, reach JSON NOT NULL, created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, last_used_at DATETIME DEFAULT NULL, created_via VARCHAR(16) NOT NULL, legacy_hash VARCHAR(64) CHARACTER SET ascii DEFAULT NULL COLLATE `ascii_bin`, id VARCHAR(36) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, INDEX idx_nowo_api_token_owner (owner_type, owner_id, created_at), INDEX idx_nowo_api_token_expires (expires_at), UNIQUE INDEX uniq_nowo_api_token_lookup (lookup_id), UNIQUE INDEX uniq_nowo_api_token_legacy (legacy_hash), PRIMARY KEY (id));
```

PostgreSQL 16+ (exact comparison by default; the unique index on `legacy_hash` allows many NULLs):

```sql
CREATE TABLE nowo_api_token (lookup_id VARCHAR(12) NOT NULL, secret_hash VARCHAR(64) NOT NULL, hash_version SMALLINT NOT NULL, owner_type VARCHAR(32) NOT NULL, owner_id VARCHAR(128) NOT NULL, name VARCHAR(80) NOT NULL, grants JSON NOT NULL, reach_everything BOOLEAN NOT NULL, reach JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_via VARCHAR(16) NOT NULL, legacy_hash VARCHAR(64) DEFAULT NULL, id VARCHAR(36) NOT NULL, PRIMARY KEY (id));
CREATE INDEX idx_nowo_api_token_owner ON nowo_api_token (owner_type, owner_id, created_at);
CREATE INDEX idx_nowo_api_token_expires ON nowo_api_token (expires_at);
CREATE UNIQUE INDEX uniq_nowo_api_token_lookup ON nowo_api_token (lookup_id);
CREATE UNIQUE INDEX uniq_nowo_api_token_legacy ON nowo_api_token (legacy_hash);
```

SQLite: the PostgreSQL statements with `CLOB` for the two JSON columns and `DATETIME` for the dates.

The XML mapping and the attribute twin (`doctrine.mapping: attribute`) produce the same table; `SchemaTest` asserts it
on every CI database.

## Next steps

- [CONFIGURATION.md](CONFIGURATION.md): the full tree.
- [USAGE.md](USAGE.md): the integration paths (standard and DDD).
- [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md): worker mode with the kernel kept alive.
