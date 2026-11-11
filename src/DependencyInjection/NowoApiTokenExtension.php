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
use Nowo\ApiTokenBundle\Application\Token\TokenCodec;
use Nowo\ApiTokenBundle\Application\Token\TokenHasher;
use Nowo\ApiTokenBundle\Application\Token\TokenHasherInterface;
use Nowo\ApiTokenBundle\Contract\ApiTokenAuthenticatorInterface;
use Nowo\ApiTokenBundle\Contract\ApiTokenIssuerInterface;
use Nowo\ApiTokenBundle\Contract\ApiTokenPolicyInterface;
use Nowo\ApiTokenBundle\Contract\ApiTokenQueryInterface;
use Nowo\ApiTokenBundle\Contract\ApiTokenRevokerInterface;
use Nowo\ApiTokenBundle\Contract\ApiTokenSettingsProviderInterface;
use Nowo\ApiTokenBundle\Contract\ModuleDefinitionProviderInterface;
use Nowo\ApiTokenBundle\Contract\ModuleRegistry;
use Nowo\ApiTokenBundle\Contract\OwnerResolverInterface;
use Nowo\ApiTokenBundle\Contract\OwnerStatusInterface;
use Nowo\ApiTokenBundle\Contract\ReachProviderInterface;
use Nowo\ApiTokenBundle\Contract\TokenRevocationListenerInterface;
use Nowo\ApiTokenBundle\Domain\ApiTokenStoreInterface;
use Nowo\ApiTokenBundle\Infrastructure\Console\IssueTokenCommand;
use Nowo\ApiTokenBundle\Infrastructure\Console\PurgeExpiredTokensCommand;
use Nowo\ApiTokenBundle\Infrastructure\Doctrine\ApiTokenMetadataListener;
use Nowo\ApiTokenBundle\Infrastructure\Owner\DoctrineOwnerResolver;
use Nowo\ApiTokenBundle\Infrastructure\Owner\UserCheckerOwnerStatus;
use Nowo\ApiTokenBundle\Infrastructure\Persistence\AfterCommitMiddleware;
use Nowo\ApiTokenBundle\Infrastructure\Persistence\AfterCommitQueue;
use Nowo\ApiTokenBundle\Infrastructure\Persistence\AfterCommitQueueResetListener;
use Nowo\ApiTokenBundle\Infrastructure\Persistence\DbalApiTokenStore;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Reference;

use function class_exists;
use function is_array;
use function sprintf;

/**
 * Loads `nowo_api_token`: validates the strict configuration tree, publishes it as container parameters and, while
 * `enabled` is true, registers the S1 core (contract 221-A §4–§6.2, §6.6, §10): the module registry, the token codec and
 * hasher, the DBAL store with its after-commit middleware, the issuer, revoker, query and authenticator services, the
 * owner defaults, the Doctrine metadata listener and the `nowo:api-token:issue` / `purge-expired` commands.
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

    /** Tag of {@see ModuleDefinitionProviderInterface} services. */
    public const TAG_MODULE_PROVIDER = 'nowo_api_token.module_provider';

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
        $container->setParameter(self::ALIAS . '.config', $config);
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
        /** @var array{type: string, class: string|null, id_field: string, resolver: string|null, status: string|null} $owner */
        $owner = $config['owner'];
        /** @var array{default_days: int, max_days: int, purge_after_days: int} $expiry */
        $expiry = $config['expiry'];
        /** @var array{allow_everything: bool, provider: string|null, max_entries: int} $reach */
        $reach = $config['reach'];
        /** @var array{version: int, pepper: string|null} $hash */
        $hash = $config['hash'];
        /** @var array{max_seconds: int, batch_size: int} $messenger */
        $messenger   = $config['messenger'];
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
            ->setArguments([new Reference('doctrine'), new Reference(AfterCommitQueue::class), $doctrine['entity_manager'], $doctrine['table_prefix'], $logger]);
        $container->setAlias(ApiTokenStoreInterface::class, DbalApiTokenStore::class);

        $this->service($container, ApiTokenSettingsProviderInterface::class, $config['settings_provider'], (new Definition(ConfigSettingsProvider::class))->setArguments([true, []]));
        $this->service($container, ReachProviderInterface::class, $reach['provider'], new Definition(NoReachChoices::class));
        $this->service($container, ApiTokenPolicyInterface::class, null, (new Definition(ConfigApiTokenPolicy::class))->setArguments([new Reference(ModuleRegistry::class), $expiry['max_days']]));
        $this->service($container, OwnerResolverInterface::class, $owner['resolver'], (new Definition(DoctrineOwnerResolver::class))->setArguments([new Reference('doctrine'), $owner['class'], $owner['id_field'], $owner['type']]));
        $this->service($container, OwnerStatusInterface::class, $owner['status'], (new Definition(UserCheckerOwnerStatus::class))->setArguments([new Reference('security.user_checker', ContainerInterface::IGNORE_ON_INVALID_REFERENCE)]));

        $store = new Reference(ApiTokenStoreInterface::class);
        $container->register(ApiTokenIssuer::class, ApiTokenIssuer::class)->setArguments([
            $store, new Reference(TokenCodec::class), new Reference(TokenHasherInterface::class), new Reference(ModuleRegistry::class),
            new Reference(ApiTokenPolicyInterface::class), new Reference(ReachProviderInterface::class), new Reference(ApiTokenSettingsProviderInterface::class),
            $dispatcher, $maxPerOwner, $expiry['max_days'], $reach['allow_everything'], $reach['max_entries'], $clock,
        ]);
        $container->setAlias(ApiTokenIssuerInterface::class, ApiTokenIssuer::class)->setPublic(true);
        $container->register(ApiTokenRevoker::class, ApiTokenRevoker::class)
            ->setArguments([$store, new TaggedIteratorArgument(self::TAG_REVOCATION_LISTENER), $dispatcher, $logger]);
        $container->setAlias(ApiTokenRevokerInterface::class, ApiTokenRevoker::class)->setPublic(true);
        $container->register(ApiTokenQuery::class, ApiTokenQuery::class)->setArguments([$store, $maxPerOwner]);
        $container->setAlias(ApiTokenQueryInterface::class, ApiTokenQuery::class)->setPublic(true);
        $container->register(ApiTokenAuthenticator::class, ApiTokenAuthenticator::class)->setArguments([
            $store, new Reference(TokenCodec::class), new Reference(TokenHasherInterface::class), new Reference(ModuleRegistry::class),
            new Reference(ApiTokenSettingsProviderInterface::class), new Reference(OwnerResolverInterface::class), new Reference(OwnerStatusInterface::class),
            $dispatcher, $clock, $logger,
        ]);
        $container->setAlias(ApiTokenAuthenticatorInterface::class, ApiTokenAuthenticator::class)->setPublic(true);

        $container->register(IssueTokenCommand::class, IssueTokenCommand::class)
            ->setArguments([new Reference(ApiTokenIssuerInterface::class), $owner['type'], $expiry['default_days']])
            ->addTag('console.command', ['command' => 'nowo:api-token:issue']);
        $container->register(PurgeExpiredTokensCommand::class, PurgeExpiredTokensCommand::class)
            ->setArguments([new Reference(ApiTokenRevoker::class), $expiry['purge_after_days'], $messenger['batch_size'], $messenger['max_seconds'], $clock])
            ->addTag('console.command', ['command' => 'nowo:api-token:purge-expired']);
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
