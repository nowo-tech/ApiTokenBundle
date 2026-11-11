# Architecture

The design source of truth is [CONTRACT.md](CONTRACT.md) (contract 221-A). This page summarises the layout the slices
follow.

## Table of contents

- [Layers](#layers)
- [Two host kinds](#two-host-kinds)
- [S0 contents](#s0-contents)
- [S1 contents](#s1-contents)

## Layers

New bundles use layered namespaces (architecture bar rule 3):

| Namespace | Holds |
| --- | --- |
| `Nowo\ApiTokenBundle\Contract` | Public ports: interfaces and value objects. No Doctrine, Symfony or HTTP type |
| `Nowo\ApiTokenBundle\Domain` | Plain-PHP domain classes, mapped in XML (`src/Resources/config/doctrine-mapping/`; not `config/doctrine`, which DoctrineBundle auto-maps by convention) |
| `Nowo\ApiTokenBundle\Application` | Bundle-implemented services behind the ports |
| `Nowo\ApiTokenBundle\Infrastructure` | DBAL stores, Symfony integration (security, Messenger, console) |
| `Nowo\ApiTokenBundle\Presentation` | Controllers, forms, Twig (UiKit + FormKit) |
| `Nowo\ApiTokenBundle\Bridge` | Optional integrations, active when the other package is installed |

## Two host kinds

- **Standard Symfony host** (default, zero config): the bundle's XML mapping is prepended on the configured entity
  manager; the host references bundle rows by id and may read them through Doctrine.
- **DDD / hexagonal host** (opt-in): `doctrine.auto_mapping: false`, the XML mapping registered on the host's own
  entity manager, every host-side extension point implemented as an SPI interface, references by id only.

Both paths run the same functional suite from S1 on.

## S0 contents

`NowoApiTokenBundle`, `DependencyInjection\NowoApiTokenExtension`, `DependencyInjection\Configuration` (strict tree with every
contract key), `DependencyInjection\ConfigNodes` (shared node definitions), the empty XML mapping folder and the seven
translation catalogues.

## S1 contents

| Layer | Classes |
| --- | --- |
| `Contract` | Value objects `OwnerRef`, `TokenId`, `LookupId`, `Grants`, `Reach`, `ReachChoice`, `ModuleDefinition`, `ModuleRegistry`, `ApiTokenView`, `ApiTokenPrincipal`, `ApiTokenPage`, `IssueRequest`, `IssuedToken`; enums `AuthFailure`, `RevocationReason`, `ModuleLevelMode`; the issuer, revoker, query and authenticator ports; the host SPIs (owner resolver / status, reach provider, policy, settings provider, revocation listener, module provider); events and exceptions |
| `Domain` | `ApiToken` (plain class, XML-mapped, holds the hash, never leaves the bundle), `ApiTokenStoreInterface`, `LookupIdTakenException` |
| `Application` | `ApiTokenIssuer`, `ApiTokenRevoker`, `ApiTokenQuery`, `ApiTokenAuthenticator`; `TokenCodec` (format, checksum), `TokenHasher` (SHA-256 / HMAC); config-backed defaults (`ConfigApiTokenPolicy`, `ConfigSettingsProvider`, `NoReachChoices`); `ModuleRegistryFactory` |
| `Infrastructure` | `DbalApiTokenStore` and the after-commit DBAL middleware (`AfterCommitMiddleware`, `AfterCommitConnection`, `AfterCommitQueue`, `AfterCommitQueueResetListener`); `ApiTokenMetadataListener` (table prefix, MySQL binary collations); the attribute twin `ApiTokenRecord`; `DoctrineOwnerResolver`, `UserCheckerOwnerStatus`; the `issue` and `purge-expired` commands |

Writes never go through an EntityManager: the store uses the DBAL connection of `doctrine.entity_manager` in its own
transaction, or joins the host's open transaction, and the after-commit queue releases listeners and events at the
outermost commit.
