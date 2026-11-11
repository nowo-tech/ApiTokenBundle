# Usage

> **S0 scaffold.** Only the configuration exists today. This page describes the integration paths the slices build
> (contract 221-A §3–§6); each section says from which slice it works.

## Table of contents

- [In a standard Symfony application](#in-a-standard-symfony-application)
- [Using it from a DDD / hexagonal application](#using-it-from-a-ddd--hexagonal-application)
- [Protecting your own API](#protecting-your-own-api)
- [FrankenPHP worker mode](#frankenphp-worker-mode)

## In a standard Symfony application

Zero configuration beyond `enabled: true` (S1+):

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
| Mapping | Set `doctrine.auto_mapping: false` and register `vendor/nowo-tech/api-token-bundle/src/Resources/config/doctrine` (type `xml`, prefix `Nowo\ApiTokenBundle\Domain`) on your own entity manager, or copy it. Reference tokens by `TokenId`. |
| Transactions | The bundle writes through DBAL in its own transaction and never flushes your EntityManager; called inside your transaction on the same connection, it joins it and holds its events until your commit. |
| Events | `ApiTokenIssuedEvent`, `ApiTokenRevokedEvent`, … carry ids and value objects only, dispatched after commit. |
| Configuration | `ApiTokenSettingsProviderInterface` and `ApiTokenPolicyInterface` replace config values when your settings live elsewhere (no hard dependency on InstanceSettings). |
| Tenancy | `ReachProviderInterface` lists the places a person may choose; `ReachFilterInterface` narrows them per request. |

## Protecting your own API

Your controllers read the principal from the request (S2): `ApiTokenContext::current($request)` returns the
`ApiTokenPrincipal` (token id, owner, grants, reach) or null. Declare your module in `modules:` and check
`$principal->grants->atLeast('reports', 'read', $registry)`; always re-check the person's own permissions: a token only
narrows them.

## FrankenPHP worker mode

The principal lives only on the request attributes; no token, owner or reach is memoised across requests. See
[FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md).
