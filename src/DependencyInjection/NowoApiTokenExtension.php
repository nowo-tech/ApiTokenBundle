<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

/**
 * Loads `nowo_api_token`: validates the strict configuration tree and publishes it as container parameters.
 *
 * S0 registers no service, route, listener or authenticator, whatever `enabled` says. The parameters exist so that
 * the host's `security.yaml` patterns can already follow `%nowo_api_token.api_prefix%` (one-value prefix rule).
 */
final class NowoApiTokenExtension extends Extension
{
    /** Configuration root and extension alias. */
    public const ALIAS = 'nowo_api_token';

    /**
     * The extension alias, `nowo_api_token`.
     */
    public function getAlias(): string
    {
        return self::ALIAS;
    }

    /**
     * Processes the configuration and sets the `nowo_api_token.*` container parameters.
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
    }
}
