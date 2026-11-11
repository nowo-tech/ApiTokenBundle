# Usage

> **S1 core.** The token store, the issuer, revoker, query and authenticator services and the two console commands
> work today (`enabled: true`). The firewall authenticator (S2), the pages (S3), the bridges (S4) and the HTTP API (S5)
> follow; each section says from which slice it works (contract 221-A §3–§6).

## Table of contents

- [In a standard Symfony application](#in-a-standard-symfony-application)
- [Using it from a DDD / hexagonal application](#using-it-from-a-ddd--hexagonal-application)
- [Issuing, checking and revoking tokens (S1)](#issuing-checking-and-revoking-tokens-s1)
- [Console commands (S1)](#console-commands-s1)
- [Events (S1)](#events-s1)
- [Protecting your own API](#protecting-your-own-api)
- [FrankenPHP worker mode](#frankenphp-worker-mode)

## In a standard Symfony application

Zero configuration beyond `enabled: true` and your user class (S1+):

```yaml
nowo_api_token:
    enabled: true
    owner: { class: App\Entity\User }   # identifier field `id` by default (owner.id_field)
    modules:
        reports: { levels: [read, write], label: 'Reports' }
```

- **Owner:** the default `DoctrineOwnerResolver` maps your user entity (`owner.class`, identifier `owner.id_field`) to
  an `OwnerRef('user', '<id>')`; the default owner status runs your `security.user_checker`.
- **Persistence:** the bundle prepends its XML mapping on `doctrine.entity_manager`; run
  `doctrine:migrations:diff` to create `nowo_api_token`.
- **Firewall (S2):** add the authenticator to a stateless firewall whose pattern derives from the bundle parameter:

  ```yaml
  security:
      firewalls:
          api:
              pattern: ^%nowo_api_token.api_prefix%/
              stateless: true
              custom_authenticators: [Nowo\ApiTokenBundle\Infrastructure\Security\ApiTokenFirewallAuthenticator]
  ```

- **Pages (S3):** `web_ui.enabled: true` for the account page, `admin_ui.enabled: true` for the operator page.

## Using it from a DDD / hexagonal application

The bundle never forces its classes into your domain:

| Concern | How a strict DDD host does it |
| --- | --- |
| Domain coupling | Your domain depends only on `Nowo\ApiTokenBundle\Contract\*`: interfaces and `final readonly` value objects (`OwnerRef`, `TokenId`, `Grants`, `Reach`, `ApiTokenView`, `ApiTokenPrincipal`). No Doctrine, Symfony or HTTP type appears in them (S1). |
| Owner | Implement `OwnerResolverInterface` (`ownerOf(object $user): OwnerRef`, `userOf(OwnerRef): ?object`) and `OwnerStatusInterface`; set `owner.resolver` / `owner.status`. Tokens reference your user by `OwnerRef` (type + id), never by a Doctrine relation. |
| Mapping | Set `doctrine.auto_mapping: false` and register `vendor/nowo-tech/api-token-bundle/src/Resources/config/doctrine-mapping` (type `xml`, prefix `Nowo\ApiTokenBundle\Domain`, `is_bundle: false`) on your own entity manager, or copy it. Set `doctrine.entity_manager` to that manager (or to a DBAL connection name): the store writes through its connection. Reference tokens by `TokenId`. |
| Transactions | The bundle writes through DBAL in its own transaction and never flushes your EntityManager; called inside your transaction on the same connection, it joins it and holds its events until your commit. |
| Events | `ApiTokenIssuedEvent`, `ApiTokenRevokedEvent`, … carry ids and value objects only, dispatched after commit. |
| Configuration | `ApiTokenSettingsProviderInterface` and `ApiTokenPolicyInterface` replace config values when your settings live elsewhere (no hard dependency on InstanceSettings). |
| Tenancy | `ReachProviderInterface` lists the places a person may choose; `ReachFilterInterface` narrows them per request. |

A DDD host configuration, as the functional suite runs it:

```yaml
nowo_api_token:
    enabled: true
    doctrine: { entity_manager: identity, auto_mapping: false, table_prefix: 'iam_' }
    owner: { type: member, resolver: App\Identity\Infrastructure\MemberOwnerResolver, status: App\Identity\Infrastructure\MemberStatus }

doctrine:
    orm:
        entity_managers:
            identity:
                mappings:
                    ApiToken: { type: xml, dir: '%kernel.project_dir%/vendor/nowo-tech/api-token-bundle/src/Resources/config/doctrine-mapping', prefix: 'Nowo\ApiTokenBundle\Domain', is_bundle: false }
```

## Issuing, checking and revoking tokens (S1)

Inject the ports (public service ids, autowirable); they take and return value objects only:

```php
use Nowo\ApiTokenBundle\Contract\{ApiTokenIssuerInterface, ApiTokenAuthenticatorInterface, ApiTokenRevokerInterface, ApiTokenQueryInterface};
use Nowo\ApiTokenBundle\Contract\{IssueRequest, OwnerRef, Grants, Reach, RevocationReason, AuthFailure};

$issued = $issuer->issue(new IssueRequest(
    owner: new OwnerRef('user', (string) $user->getId()),
    name: 'CI deploy',
    grants: new Grants(['reports' => 'read']),      // module => level; flags: new Grants([...], ['mcp' => ['destructive']])
    reach: Reach::everything(),                      // or Reach::limitedTo(['<project-uuid>', 'wiki:handbook'])
    expiresInDays: 90,
));
$issued->secret;      // the full token: show it once, never store or log it
$issued->token;       // ApiTokenView: id, lookup id, name, grants, reach, dates (no secret, no hash)

$result = $authenticator->authenticate($presented);   // ApiTokenPrincipal or an AuthFailure case
$query->ofOwner($owner);                               // ApiTokenView list, newest first
$revoker->revoke($owner, $tokenId, RevocationReason::Owner);   // false when not found or not this owner's
$revoker->revokeAll($owner, RevocationReason::OwnerAll);
```

- **Refusals:** `issue()` throws `ApiTokenIssueRefusedException` (its `reason`: `disabled`, `policy`, `grants`,
  `reach`, `expiry`, `too_many`, `duplicate_name`) for a well-formed request that is not allowed, and
  `InvalidApiTokenArgumentException` for a malformed one (name, channel, identifiers). Neither message contains a
  secret.
- **Grants** are checked against the module registry (`modules:` plus tagged `ModuleDefinitionProviderInterface`
  services), the policy's modules and the module switches. Stored grants are re-validated on every read: an unknown
  module or level is dropped, never widened.
- **Reach:** *everything* needs `reach.allow_everything: true`; a limited reach must be a non-empty subset of
  `ReachProviderInterface::choicesFor($owner)` (the default provider offers no choices).
- **Expiry** is mandatory: 1 to `min(expiry.max_days, policy max, 365)` days.
- **Authentication order** (contract §6.2): format and checksum (no database read) → row by lookup id (a miss still
  runs the constant-time comparison) → `hash_equals()` → expiry → module switch → owner resolved and active, on every
  call. *Last used* is written at most once a minute, outside any open transaction, and dispatches
  `ApiTokenUsedEvent`.
- **Revocation deletes the row**, runs the tagged `TokenRevocationListenerInterface` services, then dispatches
  `ApiTokenRevokedEvent`, all after the commit. A failing listener is logged and never undoes the revocation.
- **Inside your transaction:** called while your transaction is open on the same connection, the writes join it and
  the listeners and events wait for your outermost commit (they are dropped on rollback).

Until S2 ships the firewall authenticator, call `ApiTokenAuthenticatorInterface::authenticate()` from your own
authenticator with the `Authorization: Bearer` value; treat every `AuthFailure` the same towards the client.

## Console commands (S1)

| Command | What it does |
| --- | --- |
| `nowo:api-token:issue <owner-id> --name=… --grant=module:level [--flag=module:flag] [--reach=entry] [--days=N] [--owner-type=…]` | Issues a token through the same issuer (channel `cli`), for tests and break-glass. The secret is printed once on stdout; the summary goes to stderr |
| `nowo:api-token:purge-expired` | Deletes the tokens that expired more than `expiry.purge_after_days` ago, in batches of `messenger.batch_size`, stopping after `messenger.max_seconds` (run it again for the rest). Expired tokens are refused from the moment they expire; the purge only removes rows |

## Events (S1)

All are dispatched after the commit and carry ids and value objects only, never the secret, the hash or the presented
value:

| Event | When |
| --- | --- |
| `ApiTokenIssuedEvent` (`tokenId`, `lookupId`, `owner`, `name`, `grants`, `reachSummary`, `expiresAt`, `createdVia`) | After the insert commits |
| `ApiTokenRevokedEvent` (`tokenId`, `lookupId`, `owner`, `name`, `reason`, `actor`) | After the delete commits, once per token |
| `ApiTokenUsedEvent` (`tokenId`, `owner`, `module`: null until the S2 firewall fills it) | Only when *last used* was written (at most once a minute per token) |

## Protecting your own API

Your controllers read the principal from the request (S2): `ApiTokenContext::current($request)` returns the
`ApiTokenPrincipal` (token id, owner, grants, reach) or null. Declare your module in `modules:` and check
`$principal->grants->atLeast('reports', 'read', $registry)`; always re-check the person's own permissions: a token only
narrows them.

## FrankenPHP worker mode

The principal lives only on the request attributes; no token, owner or reach is memoised across requests. See
[FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md).
