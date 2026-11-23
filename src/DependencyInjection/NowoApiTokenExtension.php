<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\DependencyInjection;

use LogicException;
use Nowo\ApiTokenBundle\Application\Default\ConfigApiTokenPolicy;
use Nowo\ApiTokenBundle\Application\Default\ConfigSettingsProvider;
use Nowo\ApiTokenBundle\Application\Default\NoReachChoices;
use Nowo\ApiTokenBundle\Application\ModuleRegistryFactory;
use Nowo\ApiTokenBundle\Application\Service\ApiTokenAuthenticator;
use Nowo\ApiTokenBundle\Application\Service\ApiTokenIssuer;
use Nowo\ApiTokenBundle\Application\Service\ApiTokenQuery;
use Nowo\ApiTokenBundle\Application\Service\ApiTokenRevoker;
use Nowo\ApiTokenBundle\Application\Service\ReachFilterChain;
use Nowo\ApiTokenBundle\Application\Token\TokenCodec;
use Nowo\ApiTokenBundle\Application\Token\TokenHasher;
use Nowo\ApiTokenBundle\Application\Token\TokenHasherInterface;
use Nowo\ApiTokenBundle\Contract\ApiTokenAccessCheckerInterface;
use Nowo\ApiTokenBundle\Contract\ApiTokenAuthenticatorInterface;
use Nowo\ApiTokenBundle\Contract\ApiTokenIssuerInterface;
use Nowo\ApiTokenBundle\Contract\ApiTokenPolicyInterface;
use Nowo\ApiTokenBundle\Contract\ApiTokenQueryInterface;
use Nowo\ApiTokenBundle\Contract\ApiTokenRateLimiterInterface;
use Nowo\ApiTokenBundle\Contract\ApiTokenRevokerInterface;
use Nowo\ApiTokenBundle\Contract\ApiTokenSettingsProviderInterface;
use Nowo\ApiTokenBundle\Contract\ModuleDefinitionProviderInterface;
use Nowo\ApiTokenBundle\Contract\ModuleRegistry;
use Nowo\ApiTokenBundle\Contract\OwnerResolverInterface;
use Nowo\ApiTokenBundle\Contract\OwnerStatusInterface;
use Nowo\ApiTokenBundle\Contract\ReachFilterInterface;
use Nowo\ApiTokenBundle\Contract\ReachProviderInterface;
use Nowo\ApiTokenBundle\Contract\ReauthGateInterface;
use Nowo\ApiTokenBundle\Contract\TokenRevocationListenerInterface;
use Nowo\ApiTokenBundle\Domain\ApiTokenStoreInterface;
use Nowo\ApiTokenBundle\Infrastructure\Console\IssueTokenCommand;
use Nowo\ApiTokenBundle\Infrastructure\Console\PurgeExpiredTokensCommand;
use Nowo\ApiTokenBundle\Infrastructure\Doctrine\ApiTokenMetadataListener;
use Nowo\ApiTokenBundle\Infrastructure\Http\ApiTokenConflictListener;
use Nowo\ApiTokenBundle\Infrastructure\Owner\DoctrineOwnerResolver;
use Nowo\ApiTokenBundle\Infrastructure\Owner\UserCheckerOwnerStatus;
use Nowo\ApiTokenBundle\Infrastructure\Persistence\AfterCommitMiddleware;
use Nowo\ApiTokenBundle\Infrastructure\Persistence\AfterCommitQueue;
use Nowo\ApiTokenBundle\Infrastructure\Persistence\AfterCommitQueueResetListener;
use Nowo\ApiTokenBundle\Infrastructure\Persistence\DbalApiTokenStore;
use Nowo\ApiTokenBundle\Infrastructure\RateLimit\SymfonyApiTokenRateLimiter;
use Nowo\ApiTokenBundle\Infrastructure\Security\AllowAllApiTokenAccessChecker;
use Nowo\ApiTokenBundle\Infrastructure\Security\ApiTokenAdminGuard;
use Nowo\ApiTokenBundle\Infrastructure\Security\ApiTokenFirewallAuthenticator;
use Nowo\ApiTokenBundle\Infrastructure\Security\ApiTokenTokenStorageResetListener;
use Nowo\ApiTokenBundle\Infrastructure\Security\ClientIpHasher;
use Nowo\ApiTokenBundle\Infrastructure\Security\ConfigurableApiTokenAccessChecker;
use Nowo\ApiTokenBundle\Infrastructure\Security\PasswordReauthGate;
use Nowo\ApiTokenBundle\Infrastructure\Security\ReauthSession;
use Nowo\ApiTokenBundle\Presentation\Controller\AccountTokensController;
use Nowo\ApiTokenBundle\Presentation\Controller\OperatorTokensController;
use Nowo\ApiTokenBundle\Presentation\Form\CsrfActionType;
use Nowo\ApiTokenBundle\Presentation\Form\NewTokenType;
use Nowo\ApiTokenBundle\Presentation\Form\OperatorFilterType;
use Nowo\ApiTokenBundle\Presentation\Form\RevokeAllType;
use Nowo\ApiTokenBundle\Presentation\Http\FramingHeadersListener;
use Nowo\ApiTokenBundle\Presentation\Routing\ApiTokenRouteLoader;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\UiKitBundle\Dialog\RemoteDialogResponder;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

use function class_exists;
use function is_array;
use function sprintf;

/**
 * Loads `nowo_api_token`: validates the strict configuration tree, publishes it as container parameters and, while
 * `enabled` is true, registers the S1 core (contract 221-A §4–§6.2, §6.6, §10): the module registry, the token codec and
 * hasher, the DBAL store with its after-commit middleware, the issuer, revoker, query and authenticator services, the
 * owner defaults, the Doctrine metadata listener and the `nowo:api-token:issue` / `purge-expired` commands; and the S2
 * firewall integration (§6.3, §6.5, §6.7): the reach filter chain, the rate limiter, the firewall authenticator and the
 * token storage reset listener.
 *
 * `enabled: false` registers no service, listener, mapping or command. The parameters always exist, so the host's
 * `security.yaml` patterns can follow `%nowo_api_token.api_prefix%` (one-value prefix rule).
 */
final class NowoApiTokenExtension extends Extension implements PrependExtensionInterface
{
    /** Configuration root and extension alias. */
    public const ALIAS = 'nowo_api_token';

    /** Tag of {@see TokenRevocationListenerInterface} services. */
    public const TAG_REVOCATION_LISTENER = 'nowo_api_token.revocation_listener';

    /** Tag of {@see ReachFilterInterface} services (chained by priority). */
    public const TAG_REACH_FILTER = 'nowo_api_token.reach_filter';

    /** Tag of {@see ModuleDefinitionProviderInterface} services. */
    public const TAG_MODULE_PROVIDER = 'nowo_api_token.module_provider';

    /** Service id of the bundle's conflict answer policy (`conflict_response`). */
    public const CONFLICT_POLICY = 'nowo_api_token.conflict_response_policy';

    /** Doctrine mapping name of the bundle's table. */
    public const MAPPING_NAME = 'NowoApiTokenBundle';

    /**
     * The extension alias, `nowo_api_token`.
     */
    public function getAlias(): string
    {
        return self::ALIAS;
    }

    /**
     * Standard hosts (`doctrine.auto_mapping: true`, the default): registers the XML mapping (or the attribute twin)
     * on `doctrine.entity_manager` when the host configures the ORM and the bundle is enabled.
     */
    public function prepend(ContainerBuilder $container): void
    {
        if ($container->hasExtension('twig')) {
            // Twig namespace @NowoApiTokenBundle (contract §2); the host's templates/bundles/ overrides still win.
            $container->prependExtensionConfig('twig', ['paths' => [__DIR__ . '/../Resources/views' => 'NowoApiTokenBundle']]);
        }
        if (!$container->hasExtension('doctrine')) {
            return;
        }
        $config = $this->processConfiguration(new Configuration(), $container->getExtensionConfig(self::ALIAS));
        if (!$config['enabled'] || !$config['doctrine']['auto_mapping']) {
            return;
        }
        $hasOrm = false;
        foreach ($container->getExtensionConfig('doctrine') as $doctrine) {
            $hasOrm = $hasOrm || (is_array($doctrine) && isset($doctrine['orm']));
        }
        if (!$hasOrm) {
            return;
        }
        $mapping = $config['doctrine']['mapping'] === 'attribute'
            ? ['type' => 'attribute', 'dir' => __DIR__ . '/../Infrastructure/Doctrine/Entity', 'prefix' => 'Nowo\ApiTokenBundle\Infrastructure\Doctrine\Entity']
            : ['type' => 'xml', 'dir' => __DIR__ . '/../Resources/config/doctrine-mapping', 'prefix' => 'Nowo\ApiTokenBundle\Domain'];
        $container->prependExtensionConfig('doctrine', [
            'orm' => ['entity_managers' => [$config['doctrine']['entity_manager'] => ['mappings' => [self::MAPPING_NAME => $mapping + ['is_bundle' => false]]]]],
        ]);
    }

    /**
     * Processes the configuration, sets the `nowo_api_token.*` parameters and, when enabled, registers the services.
     *
     * @param array<array-key, mixed> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);
        $container->setParameter(self::ALIAS . '.enabled', $config['enabled']);
        $container->setParameter(self::ALIAS . '.api_prefix', $config['api']['prefix']);
        $container->setParameter(self::ALIAS . '.table_prefix', $config['doctrine']['table_prefix']);
        $container->setParameter(self::ALIAS . '.rate_limit_pool', $config['rate_limit']['cache_pool']);
        $container->setParameter(self::ALIAS . '.config', $this->redacted($config));
        if (!$config['enabled']) {
            return;
        }

        $this->registerCore($container, $config);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerCore(ContainerBuilder $container, array $config): void
    {
        /** @var array{entity_manager: string, table_prefix: string} $doctrine */
        $doctrine = $config['doctrine'];
        /** @var array{type: string, class: string|null, id_field: string, resolver: string|null, status: string|null, user_checker: string|null} $owner */
        $owner = $config['owner'];
        /** @var array{default_days: int, max_days: int, purge_after_days: int} $expiry */
        $expiry = $config['expiry'];
        /** @var array{allow_everything: bool, provider: string|null, max_entries: int} $reach */
        $reach = $config['reach'];
        /** @var array{version: int, pepper: string|null} $hash */
        $hash = $config['hash'];
        /** @var array{max_seconds: int, batch_size: int} $messenger */
        $messenger = $config['messenger'];
        /** @var array{attempts: int, lock_timeout_ms: int} $concurrency */
        $concurrency = $config['concurrency'];
        /** @var array{status: int, retry_after: int} $conflict */
        $conflict    = $config['conflict_response'];
        $maxPerOwner = $config['max_per_owner'];
        $logger      = new Reference('logger', ContainerInterface::IGNORE_ON_INVALID_REFERENCE);
        $clock       = new Reference('clock', ContainerInterface::IGNORE_ON_INVALID_REFERENCE);
        $dispatcher  = new Reference('event_dispatcher');

        if ($owner['resolver'] === null && $owner['class'] === null) {
            throw new LogicException('nowo_api_token: set owner.class (your user entity, for the default Doctrine resolver) or owner.resolver (an OwnerResolverInterface service) when enabled is true.');
        }
        if ($owner['class'] !== null && $owner['resolver'] === null && !class_exists($owner['class'])) {
            throw new LogicException(sprintf('nowo_api_token.owner.class "%s" does not exist.', $owner['class']));
        }

        $container->registerForAutoconfiguration(TokenRevocationListenerInterface::class)->addTag(self::TAG_REVOCATION_LISTENER);
        $container->registerForAutoconfiguration(ModuleDefinitionProviderInterface::class)->addTag(self::TAG_MODULE_PROVIDER);
        $container->registerForAutoconfiguration(ReachFilterInterface::class)->addTag(self::TAG_REACH_FILTER);

        $container->register(ModuleRegistry::class, ModuleRegistry::class)
            ->setFactory([ModuleRegistryFactory::class, 'create'])
            ->setArguments([$config['modules'], new TaggedIteratorArgument(self::TAG_MODULE_PROVIDER)]);
        $container->register(TokenCodec::class, TokenCodec::class)->setArguments([$config['token_prefix'], $config['checksum']]);
        $container->register(TokenHasher::class, TokenHasher::class)->setArguments([$hash['version'], $hash['pepper']]);
        $container->setAlias(TokenHasherInterface::class, TokenHasher::class);

        $container->register(AfterCommitQueue::class, AfterCommitQueue::class)
            ->setArguments([$logger])
            ->addTag('kernel.reset', ['method' => 'reset']);
        $reset = $container->register(AfterCommitQueueResetListener::class, AfterCommitQueueResetListener::class)
            ->setArguments([new Reference(AfterCommitQueue::class)])
            ->addTag('kernel.event_listener', ['event' => 'kernel.finish_request', 'method' => 'onFinishRequest', 'priority' => -1024])
            ->addTag('kernel.event_listener', ['event' => 'kernel.terminate', 'method' => 'onBoundary', 'priority' => -1024])
            ->addTag('kernel.event_listener', ['event' => 'console.terminate', 'method' => 'onBoundary', 'priority' => -1024]);
        foreach (['Symfony\Component\Messenger\Event\WorkerMessageHandledEvent', 'Symfony\Component\Messenger\Event\WorkerMessageFailedEvent'] as $event) {
            $reset->addTag('kernel.event_listener', ['event' => $event, 'method' => 'onBoundary', 'priority' => -1024]);
        }
        $container->register(AfterCommitMiddleware::class, AfterCommitMiddleware::class)
            ->setArguments([new Reference(AfterCommitQueue::class)])
            ->addTag('doctrine.middleware');
        $container->register(ApiTokenMetadataListener::class, ApiTokenMetadataListener::class)
            ->setArguments([$doctrine['table_prefix']])
            ->addTag('doctrine.event_listener', ['event' => 'loadClassMetadata']);
        $container->register(DbalApiTokenStore::class, DbalApiTokenStore::class)
            ->setArguments([
                new Reference('doctrine'), new Reference(AfterCommitQueue::class), $doctrine['entity_manager'], $doctrine['table_prefix'], $logger,
                $concurrency['attempts'], $concurrency['lock_timeout_ms'], $conflict['retry_after'],
            ]);
        $container->setAlias(ApiTokenStoreInterface::class, DbalApiTokenStore::class);
        $container->register(self::CONFLICT_POLICY, ConflictResponsePolicy::class)->setArguments([$conflict['status'], $conflict['retry_after']]);
        $container->register(ApiTokenConflictListener::class, ApiTokenConflictListener::class)
            ->setArguments([new Reference(self::CONFLICT_POLICY)])
            ->addTag('kernel.event_listener', ['event' => 'kernel.exception', 'method' => 'onKernelException', 'priority' => ApiTokenConflictListener::PRIORITY]);

        $this->service($container, ApiTokenSettingsProviderInterface::class, $config['settings_provider'], (new Definition(ConfigSettingsProvider::class))->setArguments([true, []]));
        $this->service($container, ReachProviderInterface::class, $reach['provider'], new Definition(NoReachChoices::class));
        $this->service($container, ApiTokenPolicyInterface::class, null, (new Definition(ConfigApiTokenPolicy::class))->setArguments([new Reference(ModuleRegistry::class), $expiry['max_days']]));
        $this->service($container, OwnerResolverInterface::class, $owner['resolver'], (new Definition(DoctrineOwnerResolver::class))->setArguments([new Reference('doctrine'), $owner['class'], $owner['id_field'], $owner['type']]));
        // The checkers are set by OwnerUserCheckerPass (the host's firewall checkers, or owner.user_checker).
        $this->service($container, OwnerStatusInterface::class, $owner['status'], (new Definition(UserCheckerOwnerStatus::class))->setArguments([[]]));
        $container->setParameter(self::ALIAS . '.owner_status', ['custom' => $owner['status'] !== null, 'user_checker' => $owner['user_checker']]);

        $store = new Reference(ApiTokenStoreInterface::class);
        $container->register(ApiTokenIssuer::class, ApiTokenIssuer::class)->setArguments([
            $store, new Reference(TokenCodec::class), new Reference(TokenHasherInterface::class), new Reference(ModuleRegistry::class),
            new Reference(ApiTokenPolicyInterface::class), new Reference(ReachProviderInterface::class), new Reference(ApiTokenSettingsProviderInterface::class),
            new Reference(OwnerResolverInterface::class), new Reference(OwnerStatusInterface::class),
            $dispatcher, $maxPerOwner, $expiry['max_days'], $reach['allow_everything'], $reach['max_entries'], $clock,
        ]);
        $container->setAlias(ApiTokenIssuerInterface::class, ApiTokenIssuer::class)->setPublic(true);
        $container->register(ApiTokenRevoker::class, ApiTokenRevoker::class)
            ->setArguments([$store, new TaggedIteratorArgument(self::TAG_REVOCATION_LISTENER), $dispatcher, $logger]);
        $container->setAlias(ApiTokenRevokerInterface::class, ApiTokenRevoker::class)->setPublic(true);
        $container->register(ApiTokenQuery::class, ApiTokenQuery::class)->setArguments([$store, $maxPerOwner]);
        $container->setAlias(ApiTokenQueryInterface::class, ApiTokenQuery::class)->setPublic(true);
        $container->register(ReachFilterChain::class, ReachFilterChain::class)->setArguments([new TaggedIteratorArgument(self::TAG_REACH_FILTER)]);
        $container->register(ApiTokenAuthenticator::class, ApiTokenAuthenticator::class)->setArguments([
            $store, new Reference(TokenCodec::class), new Reference(TokenHasherInterface::class), new Reference(ModuleRegistry::class),
            new Reference(ApiTokenSettingsProviderInterface::class), new Reference(OwnerResolverInterface::class), new Reference(OwnerStatusInterface::class),
            $dispatcher, $clock, $logger, new Reference(ReachFilterChain::class),
        ]);
        $container->setAlias(ApiTokenAuthenticatorInterface::class, ApiTokenAuthenticator::class)->setPublic(true);
        $this->registerFirewall($container, $config, $dispatcher);
        $this->registerUi($container, $config, $clock, $dispatcher);

        // Registered from their #[AsCommand] attribute (REQ-SF-003): autoconfiguration adds the console tag.
        $container->register(IssueTokenCommand::class, IssueTokenCommand::class)
            ->setAutoconfigured(true)
            ->setArguments([new Reference(ApiTokenIssuerInterface::class), $owner['type'], $expiry['default_days']]);
        $container->register(PurgeExpiredTokensCommand::class, PurgeExpiredTokensCommand::class)
            ->setAutoconfigured(true)
            ->setArguments([new Reference(ApiTokenRevoker::class), $expiry['purge_after_days'], $messenger['batch_size'], $messenger['max_seconds'], $clock, $logger]);
    }

    /**
     * S2: rate limits in the host's cache pool, the firewall authenticator and the worker-mode token storage reset.
     *
     * @param array<string, mixed> $config
     */
    private function registerFirewall(ContainerBuilder $container, array $config, Reference $dispatcher): void
    {
        /** @var array{cache_pool: string, count_rest_writes: bool, per_token: array{limit: int, interval: string}, writes: array{limit: int, interval: string}, failed_per_ip: array{limit: int, interval: string}, issue_per_owner: array{limit: int, interval: string}} $rate */
        $rate = $config['rate_limit'];
        /** @var array{on_failure: string} $firewall */
        $firewall = $config['firewall'];
        /** @var array{beacon_v1: bool} $legacy */
        $legacy = $config['legacy'];

        $container->register(ClientIpHasher::class, ClientIpHasher::class)->setArguments(['%kernel.secret%']);
        $container->register(self::ALIAS . '.rate_limit_storage', CacheStorage::class)->setArguments([new Reference($rate['cache_pool'])]);
        $factories = [];
        foreach (['per_token' => 'nowo_api_token', 'writes' => 'nowo_api_token_write', 'failed_per_ip' => 'nowo_api_token_failed_ip'] as $key => $id) {
            $container->register(self::ALIAS . '.limiter.' . $key, RateLimiterFactory::class)->setArguments([
                ['id' => $id, 'policy' => 'fixed_window', 'limit' => $rate[$key]['limit'], 'interval' => $rate[$key]['interval']],
                new Reference(self::ALIAS . '.rate_limit_storage'),
            ]);
            $factories[] = new Reference(self::ALIAS . '.limiter.' . $key);
        }
        /** @var array{max_attempts: int} $reauth */
        $reauth = $config['reauth'];
        $extra  = [
            'issue_per_owner' => ['nowo_api_token_issue', $rate['issue_per_owner']['limit'], $rate['issue_per_owner']['interval']],
            'reauth'          => ['nowo_api_token_reauth', $reauth['max_attempts'], '15 minutes'],
        ];
        $optional = [];
        foreach ($extra as $key => [$id, $limit, $interval]) {
            $container->register(self::ALIAS . '.limiter.' . $key, RateLimiterFactory::class)->setArguments([
                ['id' => $id, 'policy' => 'fixed_window', 'limit' => $limit, 'interval' => $interval],
                new Reference(self::ALIAS . '.rate_limit_storage'),
            ]);
            $optional[] = new Reference(self::ALIAS . '.limiter.' . $key);
        }
        $container->register(SymfonyApiTokenRateLimiter::class, SymfonyApiTokenRateLimiter::class)->setArguments([...$factories, new Reference(ClientIpHasher::class), ...$optional]);
        $container->setAlias(ApiTokenRateLimiterInterface::class, SymfonyApiTokenRateLimiter::class)->setPublic(true);

        // No PSR clock: Retry-After is measured on the limiter's own time base (symfony/rate-limiter reads microtime()).
        $container->register(ApiTokenFirewallAuthenticator::class, ApiTokenFirewallAuthenticator::class)->setArguments([
            new Reference(ApiTokenAuthenticatorInterface::class), new Reference(ApiTokenRateLimiterInterface::class), new Reference(TokenCodec::class),
            $dispatcher, new Reference(ClientIpHasher::class), $firewall['on_failure'], $rate['count_rest_writes'],
        ])->setArgument('$legacyBeaconV1', $legacy['beacon_v1']);
        // Removed by FirewallPass when SecurityBundle is not enabled (no token storage); FirewallPass sets the firewall
        // contexts that list the authenticator.
        $container->register(ApiTokenTokenStorageResetListener::class, ApiTokenTokenStorageResetListener::class)
            ->setArguments([new Reference(ApiTokenTokenStorageResetListener::TOKEN_STORAGE), []])
            ->addTag('kernel.event_listener', ['event' => 'kernel.finish_request', 'method' => 'onFinishRequest', 'priority' => ApiTokenTokenStorageResetListener::FINISH_PRIORITY]);
    }

    /**
     * S3: the account pages (`web_ui`), the operator page (`admin_ui`), the re-auth step and the framing headers. Nothing
     * while both areas are off.
     *
     * @param array<string, mixed> $config
     */
    private function registerUi(ContainerBuilder $container, array $config, Reference $clock, Reference $dispatcher): void
    {
        /** @var array{enabled: bool, path_prefix: string, layout_template: string|null, templates: array{list: string|null, created: string|null}} $web */
        $web = $config['web_ui'];
        /** @var array{enabled: bool, path_prefix: string, security: array{access_roles: list<string>, access_checker: string|null, allow_unauthenticated: bool}} $admin */
        $admin = $config['admin_ui'];
        /** @var array{enabled: bool, fresh_seconds: int, max_attempts: int, gate: string|null} $reauth */
        $reauth = $config['reauth'];
        /** @var array{default_days: int, choices: list<int>} $expiry */
        $expiry = $config['expiry'];
        /** @var array{allow_everything: bool} $reach */
        $reach = $config['reach'];
        $container->setParameter(self::ALIAS . '.ui', ['web' => $web['enabled'], 'admin' => $admin['enabled'], 'allow_unauthenticated' => $admin['security']['allow_unauthenticated'], 'reauth_gate' => $reauth['enabled'] && $reauth['gate'] === null]);
        $container->register(ApiTokenRouteLoader::class, ApiTokenRouteLoader::class)
            ->setArguments([['enabled' => $web['enabled'], 'path_prefix' => $web['path_prefix']], ['enabled' => $admin['enabled'], 'path_prefix' => $admin['path_prefix']], '%kernel.environment%'])
            ->addTag('routing.loader');
        if (!$web['enabled'] && !$admin['enabled']) {
            return;
        }
        $container->register(FramingHeadersListener::class, FramingHeadersListener::class)
            ->addTag('kernel.event_listener', ['event' => 'kernel.response', 'method' => 'onKernelResponse', 'priority' => FramingHeadersListener::PRIORITY]);
        foreach ([CsrfActionType::class, NewTokenType::class, RevokeAllType::class, OperatorFilterType::class] as $type) {
            $container->register($type, $type)->addTag('form.type');
        }
        $common = [
            'twig'   => new Reference('twig'),
            'router' => new Reference('router'),
            'forms'  => new Reference('form.factory'),
        ];

        if ($web['enabled']) {
            $this->service($container, ReauthGateInterface::class, $reauth['gate'], (new Definition(PasswordReauthGate::class))->setArguments([new Reference('security.user_password_hasher', ContainerInterface::NULL_ON_INVALID_REFERENCE)]));
            $container->register(ReauthSession::class, ReauthSession::class)->setArguments([
                new Reference(ReauthGateInterface::class), new Reference(OwnerResolverInterface::class), new Reference(ApiTokenRateLimiterInterface::class),
                $reauth['enabled'], $reauth['fresh_seconds'], $clock,
            ]);
            $container->register(AccountTokensController::class, AccountTokensController::class)
                ->setPublic(true)
                ->addTag('controller.service_arguments')
                ->setArguments([
                    new Reference(ApiTokenIssuerInterface::class), new Reference(ApiTokenRevokerInterface::class), new Reference(ApiTokenQueryInterface::class),
                    new Reference(ModuleRegistry::class), new Reference(ApiTokenPolicyInterface::class), new Reference(ApiTokenSettingsProviderInterface::class),
                    new Reference(ReachProviderInterface::class), new Reference(OwnerResolverInterface::class), new Reference(ApiTokenRateLimiterInterface::class),
                    new Reference(ReauthSession::class), $common['forms'], $common['twig'], $common['router'],
                    new Reference('security.token_storage'), new Reference('security.authorization_checker'), new Reference('security.csrf.token_manager'),
                    new Reference('translator'), new Reference(RemoteDialogResponder::class),
                    $config['token_prefix'], $reach['allow_everything'], $expiry['choices'], $expiry['default_days'], $web['layout_template'], $web['templates'], $clock,
                ]);
        }
        if ($admin['enabled']) {
            $security = $admin['security'];
            $default  = $security['allow_unauthenticated']
                ? new Definition(AllowAllApiTokenAccessChecker::class)
                : (new Definition(ConfigurableApiTokenAccessChecker::class))->setArguments([new Reference('security.authorization_checker'), $security['access_roles']]);
            $this->service($container, ApiTokenAccessCheckerInterface::class, $security['access_checker'], $default);
            $container->register(ApiTokenAdminGuard::class, ApiTokenAdminGuard::class)->setArguments([
                new Reference(ApiTokenAccessCheckerInterface::class), $dispatcher, new Reference('security.token_storage', ContainerInterface::NULL_ON_INVALID_REFERENCE), $security['allow_unauthenticated'],
            ]);
            $container->register(OperatorTokensController::class, OperatorTokensController::class)
                ->setPublic(true)
                ->addTag('controller.service_arguments')
                ->setArguments([
                    new Reference(ApiTokenQueryInterface::class), new Reference(ApiTokenRevokerInterface::class), new Reference(ApiTokenAdminGuard::class),
                    new Reference(OwnerResolverInterface::class), new Reference(ModuleRegistry::class), $common['forms'], $common['twig'], $common['router'],
                    $config['token_prefix'], $clock,
                ]);
        }
    }

    /**
     * The processed tree without secrets (the pepper never sits in a container parameter, LW-I6).
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function redacted(array $config): array
    {
        if (is_array($config['hash'] ?? null) && ($config['hash']['pepper'] ?? null) !== null) {
            $config['hash']['pepper'] = '[redacted]';
        }

        return $config;
    }

    /**
     * Registers the default of a port, or aliases the port to the host's service. The port id is a public alias.
     */
    private function service(ContainerBuilder $container, string $port, ?string $hostService, Definition $default): void
    {
        if ($hostService !== null) {
            $container->setAlias($port, $hostService)->setPublic(true);

            return;
        }
        $container->setDefinition($default->getClass() ?? $port, $default);
        $container->setAlias($port, $default->getClass() ?? $port)->setPublic(true);
    }
}
