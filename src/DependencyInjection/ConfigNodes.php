<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\ScalarNodeDefinition;

use function in_array;
use function is_string;
use function preg_match;

/**
 * Reusable node definitions of the strict configuration tree (REQ-SF-006): every error names the path and the
 * allowed values.
 */
final class ConfigNodes
{
    /** Value of a tri-state switch: enabled when the integration is installed. */
    public const AUTO = 'auto';

    /**
     * `true`, `false` or `"auto"`.
     */
    public static function auto(string $name, bool|string $default): ScalarNodeDefinition
    {
        $node = new ScalarNodeDefinition($name);
        $node->defaultValue($default)
            ->info('true, false or "auto" (on when the integration is installed)')
            ->validate()
                ->ifTrue(static fn (mixed $value): bool => !in_array($value, [true, false, self::AUTO], true))
                ->thenInvalid('Expected true, false or "auto", got %s.')
            ->end();

        return $node;
    }

    /**
     * An absolute URL path without a trailing slash, e.g. `/api` or `/admin/tokens`.
     */
    public static function path(string $name, string $default): ScalarNodeDefinition
    {
        $node = new ScalarNodeDefinition($name);
        $node->defaultValue($default)
            ->validate()
                ->ifTrue(static fn (mixed $value): bool => !is_string($value) || preg_match('#^(/[A-Za-z0-9._~-]+)+$#', $value) !== 1)
                ->thenInvalid('Expected an absolute path without a trailing slash (e.g. "/api"), got %s.')
            ->end();

        return $node;
    }

    /**
     * An optional service id (null = the bundle default).
     */
    public static function service(string $name, string $info = 'Service id; null = the bundle default'): ScalarNodeDefinition
    {
        $node = new ScalarNodeDefinition($name);
        $node->defaultNull()->info($info)
            ->validate()
                ->ifTrue(static fn (mixed $value): bool => $value !== null && (!is_string($value) || $value === ''))
                ->thenInvalid('Expected a service id or null, got %s.')
            ->end();

        return $node;
    }

    /**
     * A rate limit `{limit, interval}` (fixed window), e.g. `{limit: 100, interval: '1 minute'}`.
     */
    public static function rate(string $name, int $limit, string $interval): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition($name);
        $node->addDefaultsIfNotSet()
            ->children()
                ->integerNode('limit')->defaultValue($limit)->min(1)->end()
                ->scalarNode('interval')->defaultValue($interval)
                    ->validate()
                        ->ifTrue(static fn (mixed $value): bool => !is_string($value) || preg_match('/^[1-9]\d{0,5} (second|minute|hour|day)s?$/', $value) !== 1)
                        ->thenInvalid('Expected an interval such as "1 minute" or "15 minutes" (second, minute, hour, day), got %s.')
                    ->end()
                ->end()
            ->end();

        return $node;
    }

    /**
     * A five-field cron expression (Symfony Scheduler recipe).
     */
    public static function cron(string $name, string $default): ScalarNodeDefinition
    {
        $node = new ScalarNodeDefinition($name);
        $node->defaultValue($default)
            ->validate()
                ->ifTrue(static fn (mixed $value): bool => !is_string($value) || preg_match('#^[0-9*/,-]+( [0-9*/,-]+){4}$#', $value) !== 1)
                ->thenInvalid('Expected a five-field cron expression such as "20 3 * * *", got %s.')
            ->end();

        return $node;
    }

    /**
     * The `doctrine` block shared by the three new bundles (contract §5: bundle-owned tables, XML mapping by default).
     */
    public static function doctrine(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('doctrine');
        $node->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('entity_manager')->defaultValue('default')->cannotBeEmpty()->end()
                ->scalarNode('table_prefix')->defaultValue('nowo_')
                    ->validate()
                        ->ifTrue(static fn (mixed $value): bool => !is_string($value) || preg_match('/^([a-z][a-z0-9_]{0,15})?$/', $value) !== 1)
                        ->thenInvalid('Expected a table prefix of lower-case letters, digits and "_" (at most 16, may be empty), got %s.')
                    ->end()
                ->end()
                ->booleanNode('auto_mapping')->defaultTrue()->info('false = DDD host: register the XML mapping on your own entity manager')->end()
                ->enumNode('mapping')->values(['xml', 'attribute'])->defaultValue('xml')->info('xml (plain domain class, default) or attribute (twin record class, same schema)')->end()
            ->end();

        return $node;
    }

    /**
     * The operator page block (`REQ-UI-002`): roles, optional custom checker, never open by default.
     */
    public static function adminUi(string $pathPrefix): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('admin_ui');
        $node->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->defaultFalse()->end()
                ->append(self::path('path_prefix', $pathPrefix))
                ->arrayNode('security')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('access_roles')
                            ->requiresAtLeastOneElement()
                            ->defaultValue(['ROLE_ADMIN'])
                            ->scalarPrototype()->cannotBeEmpty()->end()
                        ->end()
                        ->append(self::service('access_checker'))
                        ->booleanNode('allow_unauthenticated')->defaultFalse()->info('Demo / dev only')->end()
                    ->end()
                ->end()
            ->end();

        return $node;
    }

    /**
     * The optional public HTTP API block: `<prefix>/<segment>/v<N>/…`, prefix published as `%<alias>.api_prefix%`.
     */
    public static function api(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('api');
        $node->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->defaultFalse()->end()
                ->append(self::path('prefix', '/api'))
                ->booleanNode('revocation_endpoint')->defaultFalse()->info('POST <prefix>/tokens/v1/revocations (Q-O9), off by default')->end()
            ->end();

        return $node;
    }
}
