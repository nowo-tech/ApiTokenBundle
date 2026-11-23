# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/api-token-bundle`  
**Last audited**: 2026-11-23 (S1 + audit LW fixes, 0.2.0 concurrency, S2 firewall + limits, S3 pages)

Scope: every file under `src/`. Audit command: `find src -type f | wc -l` → **131**.

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Application/Default/ConfigApiTokenPolicy.php` | Defaults | FR-ISSUE-001, FR-AUTH-001 |
| `src/Application/Default/ConfigSettingsProvider.php` | Defaults | FR-ISSUE-001, FR-AUTH-001 |
| `src/Application/Default/NoReachChoices.php` | Defaults | FR-ISSUE-001, FR-AUTH-001 |
| `src/Application/ModuleRegistryFactory.php` | Grants | FR-GRANT-001 |
| `src/Application/Service/ApiTokenAuthenticator.php` | Authenticate | FR-AUTH-001 |
| `src/Application/Service/ApiTokenIssuer.php` | Issue | FR-ISSUE-001, FR-OWNER-001 |
| `src/Application/Service/ApiTokenQuery.php` | Query | FR-QUERY-001 |
| `src/Application/Service/ApiTokenRevoker.php` | Revoke | FR-REVOKE-001 |
| `src/Application/Service/ReachFilterChain.php` | Reach filters | FR-REACH-001 |
| `src/Application/Token/GeneratedToken.php` | Secrets | FR-FORMAT-001, FR-SECRET-001 |
| `src/Application/Token/TokenCodec.php` | Format and hash | FR-FORMAT-001, FR-HASH-001 |
| `src/Application/Token/TokenHasher.php` | Format and hash | FR-FORMAT-001, FR-HASH-001 |
| `src/Application/Token/TokenHasherInterface.php` | Format and hash | FR-FORMAT-001, FR-HASH-001 |
| `src/Contract/ApiTokenAccessCheckerInterface.php` | Operator page | FR-ADMIN-001 |
| `src/Contract/ApiTokenAuthenticatorInterface.php` | Ports | FR-PORT-001, FR-AUTH-001 |
| `src/Contract/ApiTokenIssuerInterface.php` | Ports | FR-PORT-001, FR-ISSUE-001 |
| `src/Contract/ApiTokenPage.php` | Ports | FR-PORT-001, FR-QUERY-001 |
| `src/Contract/ApiTokenPolicyInterface.php` | Ports | FR-PORT-001 |
| `src/Contract/ApiTokenPrincipal.php` | Ports | FR-PORT-001 |
| `src/Contract/ApiTokenQueryInterface.php` | Ports | FR-PORT-001, FR-QUERY-001 |
| `src/Contract/ApiTokenRateLimiterInterface.php` | Ports | FR-PORT-001, FR-RATE-001 |
| `src/Contract/ApiTokenRevokerInterface.php` | Ports | FR-PORT-001, FR-REVOKE-001 |
| `src/Contract/ApiTokenSettingsProviderInterface.php` | Ports | FR-PORT-001 |
| `src/Contract/ApiTokenView.php` | Ports | FR-PORT-001 |
| `src/Contract/AuthFailure.php` | Ports | FR-PORT-001 |
| `src/Contract/Event/ApiTokenAccessCheckEvent.php` | Operator page | FR-ADMIN-001 |
| `src/Contract/Event/ApiTokenAuthFailedEvent.php` | Events | FR-EVENT-002 |
| `src/Contract/Event/ApiTokenEvents.php` | Events | FR-EVENT-001 |
| `src/Contract/Event/ApiTokenIssuedEvent.php` | Events | FR-EVENT-001 |
| `src/Contract/Event/ApiTokenRevokedEvent.php` | Events | FR-EVENT-001 |
| `src/Contract/Event/ApiTokenUsedEvent.php` | Events | FR-EVENT-001 |
| `src/Contract/Exception/ApiTokenConflictException.php` | Concurrency | FR-CONC-001 |
| `src/Contract/Exception/ApiTokenIssueRefusedException.php` | Ports | FR-PORT-001, FR-ISSUE-001 |
| `src/Contract/Exception/InvalidApiTokenArgumentException.php` | Ports | FR-PORT-001, FR-ISSUE-001 |
| `src/Contract/Grants.php` | Grants | FR-PORT-001, FR-GRANT-001 |
| `src/Contract/IssueRequest.php` | Ports | FR-PORT-001 |
| `src/Contract/IssuedToken.php` | Secrets | FR-PORT-001, FR-SECRET-001 |
| `src/Contract/LookupId.php` | Ports | FR-PORT-001 |
| `src/Contract/ModuleDefinition.php` | Grants | FR-PORT-001, FR-GRANT-001 |
| `src/Contract/ModuleDefinitionProviderInterface.php` | Grants | FR-PORT-001, FR-GRANT-001 |
| `src/Contract/ModuleLevelMode.php` | Grants | FR-PORT-001, FR-GRANT-001 |
| `src/Contract/ModuleRegistry.php` | Grants | FR-PORT-001, FR-GRANT-001 |
| `src/Contract/OwnerRef.php` | Hosts | FR-PORT-001, FR-HOST-001, FR-OWNER-001 |
| `src/Contract/OwnerResolverInterface.php` | Hosts | FR-PORT-001, FR-HOST-001, FR-OWNER-001 |
| `src/Contract/OwnerStatusInterface.php` | Hosts | FR-PORT-001, FR-HOST-001, FR-OWNER-001 |
| `src/Contract/RateLimitScope.php` | Ports | FR-PORT-001, FR-RATE-001 |
| `src/Contract/RateLimitState.php` | Ports | FR-PORT-001, FR-RATE-001 |
| `src/Contract/Reach.php` | Ports | FR-PORT-001 |
| `src/Contract/ReachChoice.php` | Ports | FR-PORT-001 |
| `src/Contract/ReachFilterInterface.php` | Ports | FR-PORT-001, FR-REACH-001 |
| `src/Contract/ReachProviderInterface.php` | Ports | FR-PORT-001 |
| `src/Contract/ReauthGateInterface.php` | Re-auth | FR-REAUTH-001 |
| `src/Contract/RevocationReason.php` | Ports | FR-PORT-001 |
| `src/Contract/SensitiveValue.php` | Secrets | FR-SECRET-001 |
| `src/Contract/TokenId.php` | Ports | FR-PORT-001 |
| `src/Contract/TokenRevocationListenerInterface.php` | Ports | FR-PORT-001, FR-REVOKE-001 |
| `src/DependencyInjection/Compiler/FirewallPass.php` | Firewall | FR-FIREWALL-002, FR-RATE-001 |
| `src/DependencyInjection/Compiler/OwnerUserCheckerPass.php` | Configuration | FR-CONFIG-001, FR-OWNER-001 |
| `src/DependencyInjection/Compiler/RequireDoctrinePass.php` | Configuration | FR-CONFIG-001, FR-HOST-001 |
| `src/DependencyInjection/Compiler/UiPass.php` | Pages | FR-PAGE-003 |
| `src/DependencyInjection/ConfigNodes.php` | Configuration | FR-CONFIG-001 |
| `src/DependencyInjection/Configuration.php` | Configuration | FR-CONFIG-001 |
| `src/DependencyInjection/NowoApiTokenExtension.php` | Configuration | FR-CONFIG-001, FR-CONFIG-002, FR-DB-002 |
| `src/Domain/ApiToken.php` | Persistence | FR-STORE-001, FR-HASH-001 |
| `src/Domain/ApiTokenOwnerLock.php` | Concurrency | FR-CONC-001 |
| `src/Domain/ApiTokenStoreInterface.php` | Persistence | FR-STORE-001, FR-HASH-001 |
| `src/Domain/LookupIdTakenException.php` | Persistence | FR-STORE-001, FR-HASH-001 |
| `src/Infrastructure/Console/IssueTokenCommand.php` | Console | FR-CLI-001 |
| `src/Infrastructure/Console/PurgeExpiredTokensCommand.php` | Console | FR-CLI-001 |
| `src/Infrastructure/Doctrine/ApiTokenMetadataListener.php` | Persistence | FR-DB-001, FR-DB-002 |
| `src/Infrastructure/Doctrine/Entity/ApiTokenOwnerLockRecord.php` | Concurrency | FR-CONC-001, FR-DB-002 |
| `src/Infrastructure/Doctrine/Entity/ApiTokenRecord.php` | Persistence | FR-DB-001, FR-DB-002 |
| `src/Infrastructure/Http/ApiTokenConflictListener.php` | Concurrency | FR-CONC-001 |
| `src/Infrastructure/Owner/DoctrineOwnerResolver.php` | Hosts | FR-HOST-001, FR-OWNER-001 |
| `src/Infrastructure/Owner/UserCheckerOwnerStatus.php` | Hosts | FR-HOST-001, FR-OWNER-001 |
| `src/Infrastructure/Persistence/AfterCommitConnection.php` | Persistence | FR-STORE-001, FR-EVENT-001 |
| `src/Infrastructure/Persistence/AfterCommitMiddleware.php` | Persistence | FR-STORE-001, FR-EVENT-001 |
| `src/Infrastructure/Persistence/AfterCommitQueue.php` | Persistence | FR-STORE-001, FR-EVENT-001 |
| `src/Infrastructure/Persistence/AfterCommitQueueResetListener.php` | Persistence | FR-STORE-001, FR-EVENT-001 |
| `src/Infrastructure/Persistence/DbalApiTokenStore.php` | Persistence | FR-STORE-001, FR-EVENT-001 |
| `src/Infrastructure/RateLimit/SymfonyApiTokenRateLimiter.php` | Rate limits | FR-RATE-001 |
| `src/Infrastructure/Security/AllowAllApiTokenAccessChecker.php` | Operator page | FR-ADMIN-001 |
| `src/Infrastructure/Security/ApiTokenAdminGuard.php` | Operator page | FR-ADMIN-001 |
| `src/Infrastructure/Security/ApiTokenAuthenticationException.php` | Firewall | FR-FIREWALL-001 |
| `src/Infrastructure/Security/ApiTokenContext.php` | Firewall | FR-FIREWALL-001 |
| `src/Infrastructure/Security/ApiTokenFirewallAuthenticator.php` | Firewall | FR-FIREWALL-001, FR-RATE-001, FR-EVENT-002 |
| `src/Infrastructure/Security/ApiTokenRateLimitedException.php` | Firewall | FR-FIREWALL-001, FR-RATE-001 |
| `src/Infrastructure/Security/ApiTokenTokenStorageResetListener.php` | Firewall | FR-WORKER-002 |
| `src/Infrastructure/Security/ClientIpHasher.php` | Rate limits | FR-RATE-001, FR-EVENT-002 |
| `src/Infrastructure/Security/ConfigurableApiTokenAccessChecker.php` | Operator page | FR-ADMIN-001 |
| `src/Infrastructure/Security/PasswordReauthGate.php` | Re-auth | FR-REAUTH-001 |
| `src/Infrastructure/Security/ReauthSession.php` | Re-auth | FR-REAUTH-001 |
| `src/NowoApiTokenBundle.php` | Bundle | FR-BUNDLE-001 |
| `src/Presentation/Controller/AccountTokensController.php` | Pages | FR-PAGE-001 |
| `src/Presentation/Controller/OperatorTokensController.php` | Operator page | FR-ADMIN-001 |
| `src/Presentation/Form/CsrfActionType.php` | Pages | FR-PAGE-001 |
| `src/Presentation/Form/NewTokenType.php` | Pages | FR-PAGE-001 |
| `src/Presentation/Form/OperatorFilterType.php` | Operator page | FR-ADMIN-001 |
| `src/Presentation/Form/ReauthFieldTrait.php` | Re-auth | FR-REAUTH-001 |
| `src/Presentation/Form/RevokeAllType.php` | Pages | FR-PAGE-001 |
| `src/Presentation/Http/FramingHeadersListener.php` | Pages | FR-PAGE-002 |
| `src/Presentation/Routing/ApiTokenRouteLoader.php` | Pages | FR-PAGE-001 |
| `src/Presentation/Routing/ControllerAttributeLoader.php` | Pages | FR-PAGE-001 |
| `src/Presentation/View/AccountPages.php` | Pages | FR-PAGE-001 |
| `src/Presentation/View/OperatorPages.php` | Operator page | FR-ADMIN-001 |
| `src/Presentation/View/TokenRow.php` | Pages | FR-PAGE-001 |
| `src/Resources/config/doctrine-mapping/ApiToken.orm.xml` | Persistence | FR-DB-001, FR-DB-002 |
| `src/Resources/config/doctrine-mapping/ApiTokenOwnerLock.orm.xml` | Concurrency | FR-CONC-001, FR-DB-002 |
| `src/Resources/config/doctrine-mapping/README.md` | Persistence | FR-DB-001, FR-DB-002 |
| `src/Resources/translations/NowoApiTokenBundle.de.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoApiTokenBundle.en.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoApiTokenBundle.es.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoApiTokenBundle.fr.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoApiTokenBundle.it.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoApiTokenBundle.nl.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoApiTokenBundle.pt.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/validators.de.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/validators.en.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/validators.es.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/validators.fr.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/validators.it.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/validators.nl.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/validators.pt.yaml` | Translations | FR-I18N-001 |
| `src/Resources/views/_row.html.twig` | Pages | FR-PAGE-001 |
| `src/Resources/views/account/_created.html.twig` | Pages | FR-PAGE-001 |
| `src/Resources/views/account/_new_fragment.html.twig` | Pages | FR-PAGE-001 |
| `src/Resources/views/account/_revoke_all_fragment.html.twig` | Pages | FR-PAGE-001 |
| `src/Resources/views/account/list.html.twig` | Pages | FR-PAGE-001 |
| `src/Resources/views/account/new.html.twig` | Pages | FR-PAGE-001 |
| `src/Resources/views/account/revoke_all.html.twig` | Pages | FR-PAGE-001 |
| `src/Resources/views/operator/list.html.twig` | Pages | FR-PAGE-001 |

| Total production sources | 131 |
| --- | --- |
