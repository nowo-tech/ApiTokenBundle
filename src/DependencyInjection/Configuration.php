<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

use function array_unique;
use function count;
use function is_int;
use function is_string;
use function preg_match;
use function sprintf;

/**
 * Strict configuration tree of `nowo_api_token` (contract 221-A §7). Every key exists from S0 so hosts can write the
 * final configuration now; each slice wires the keys it implements (S1: core services, persistence, commands).
 */
final class Configuration implements ConfigurationInterface
{
    /** Module keys, level names and owner types: `^[a-z][a-z0-9_]{0,31}$`. */
    public const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/';

    /** Tokens always expire (Q-O5): hosts may lower the maximum, never lift it. */
    public const MAX_TTL_DAYS = 365;

    /** Upper bound of `max_per_owner`. */
    public const MAX_PER_OWNER_CAP = 1000;

    /**
     * The `nowo_api_token` tree.
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(NowoApiTokenExtension::ALIAS);
        $root        = $treeBuilder->getRootNode();

        $root
            ->children()
                ->booleanNode('enabled')->defaultFalse()->info('Nothing is reachable while false')->end()
                ->scalarNode('token_prefix')->defaultValue('nwt_')
                    ->validate()
                        ->ifTrue(static fn (mixed $value): bool => !is_string($value) || preg_match('/^[a-z]{2,8}_$/', $value) !== 1)
                        ->thenInvalid('Expected 2 to 8 lower-case letters and "_" (e.g. "nwt_"), got %s.')
                    ->end()
                ->end()
                ->booleanNode('checksum')->defaultTrue()->info('CRC32 base62 suffix: offline typo check and secret scanning (Q-O3)')->end()
                ->arrayNode('hash')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('version')->values([1, 2])->defaultValue(1)->info('1 = SHA-256 (default, Q-O3); 2 = HMAC-SHA256 with a pepper')->end()
                        ->scalarNode('pepper')->defaultNull()->info('Required by version 2, from an environment variable')->end()
                    ->end()
                    ->validate()
                        ->ifTrue(
                            /** @param array{version: int, pepper: mixed} $hash */
                            static fn (array $hash): bool => $hash['version'] === 2 && (!is_string($hash['pepper']) || $hash['pepper'] === ''),
                        )
                        ->thenInvalid('hash.version 2 needs a non-empty hash.pepper (read it from an environment variable), got %s.')
                    ->end()
                ->end()
                ->append(ConfigNodes::doctrine())
                ->arrayNode('owner')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('type')->defaultValue('user')
                            ->validate()
                                ->ifTrue(static fn (mixed $value): bool => !is_string($value) || preg_match(self::KEY_PATTERN, $value) !== 1)
                                ->thenInvalid('Expected an owner type matching ^[a-z][a-z0-9_]{0,31}$, got %s.')
                            ->end()
                        ->end()
                        ->scalarNode('class')->defaultNull()->info('Host user class of the default Doctrine owner resolver')->end()
                        ->scalarNode('id_field')->defaultValue('id')->cannotBeEmpty()
                            ->validate()
                                ->ifTrue(static fn (mixed $value): bool => !is_string($value) || preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D', $value) !== 1)
                                ->thenInvalid('Expected a mapped field name, got %s.')
                            ->end()
                        ->end()
                        ->append(ConfigNodes::service('resolver', 'OwnerResolverInterface service; null = Doctrine resolver'))
                        ->append(ConfigNodes::service('status', 'OwnerStatusInterface service; null = user checker'))
                        ->append(ConfigNodes::service('user_checker', 'UserCheckerInterface service of the default owner status; null = the user checkers of every firewall; "none" = the resolver alone decides'))
                    ->end()
                ->end()
                ->append($this->modules())
                ->arrayNode('bridges')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->append(ConfigNodes::auto('task_board', ConfigNodes::AUTO))
                        ->append(ConfigNodes::auto('wiki', ConfigNodes::AUTO))
                        ->append(ConfigNodes::auto('mcp_kit', ConfigNodes::AUTO))
                        ->append(ConfigNodes::auto('time_track', false))
                        ->append(ConfigNodes::auto('http_log', ConfigNodes::AUTO))
                        ->append(ConfigNodes::auto('user_kit', ConfigNodes::AUTO))
                        ->append(ConfigNodes::auto('audit_kit', ConfigNodes::AUTO))
                        ->append(ConfigNodes::auto('auth_kit', ConfigNodes::AUTO))
                    ->end()
                ->end()
                ->arrayNode('reach')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('allow_everything')->defaultTrue()->end()
                        ->append(ConfigNodes::service('provider', 'ReachProviderInterface service; null = no choices'))
                        ->integerNode('max_entries')->defaultValue(200)->min(1)->max(1000)->end()
                    ->end()
                ->end()
                ->append($this->expiry())
                ->integerNode('max_per_owner')->defaultValue(20)->min(1)->max(self::MAX_PER_OWNER_CAP)->end()
                ->arrayNode('reauth')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->integerNode('fresh_seconds')->defaultValue(300)->min(30)->max(3600)->end()
                        ->integerNode('max_attempts')->defaultValue(5)->min(1)->max(20)->end()
                        ->append(ConfigNodes::service('gate', 'ReauthGateInterface service; null = password gate'))
                    ->end()
                ->end()
                ->arrayNode('rate_limit')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('cache_pool')->defaultValue('cache.rate_limiter')->cannotBeEmpty()->end()
                        ->append(ConfigNodes::rate('per_token', 100, '1 minute'))
                        ->append(ConfigNodes::rate('writes', 30, '1 minute'))
                        ->booleanNode('count_rest_writes')->defaultFalse()->end()
                        ->append(ConfigNodes::rate('failed_per_ip', 20, '15 minutes'))
                        ->append(ConfigNodes::rate('issue_per_owner', 10, '1 hour'))
                    ->end()
                ->end()
                ->arrayNode('firewall')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('on_failure')->values(['pass', 'unauthorized'])->defaultValue('pass')->end()
                    ->end()
                ->end()
                ->arrayNode('legacy')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('beacon_v1')->defaultFalse()->info('Dual-read of migrated Beacon v1 tokens (contract §9.1)')->end()
                    ->end()
                ->end()
                ->arrayNode('http_log')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('erase_usage_on_revoke')->defaultFalse()->end()
                    ->end()
                ->end()
                ->arrayNode('schedule')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                        ->append(ConfigNodes::cron('purge_expired', '20 3 * * *'))
                        ->append(ConfigNodes::cron('notify_expiring', '0 8 * * *'))
                    ->end()
                ->end()
                ->arrayNode('messenger')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('max_seconds')->defaultValue(50)->min(1)->max(3600)->end()
                        ->integerNode('batch_size')->defaultValue(500)->min(1)->max(5000)->end()
                    ->end()
                ->end()
                ->arrayNode('revoke_on_sessions_revoked')
                    ->info('UserKit RevocationReason values that also revoke the tokens')
                    ->scalarPrototype()->cannotBeEmpty()->end()
                ->end()
                ->append(ConfigNodes::api())
                ->arrayNode('web_ui')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                        ->append(ConfigNodes::path('path_prefix', '/account/security/api-tokens'))
                        ->scalarNode('layout_template')->defaultNull()->end()
                        ->arrayNode('templates')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('list')->defaultNull()->end()
                                ->scalarNode('created')->defaultNull()->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->append(ConfigNodes::adminUi('/admin/api-tokens'))
                ->append(ConfigNodes::service('settings_provider', 'ApiTokenSettingsProviderInterface service; null = configuration values'))
            ->end();

        return $treeBuilder;
    }

    /**
     * `modules`: host modules `{key: {levels, flags, label}}`, merged with the bridge-provided modules.
     */
    private function modules(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('modules');
        $node
            ->useAttributeAsKey('key')
            ->arrayPrototype()
                ->children()
                    ->arrayNode('levels')
                        ->info('Ordered low to high')
                        ->isRequired()
                        ->requiresAtLeastOneElement()
                        ->scalarPrototype()->end()
                    ->end()
                    ->arrayNode('flags')
                        ->scalarPrototype()->end()
                    ->end()
                    ->scalarNode('label')->defaultValue('')->end()
                ->end()
            ->end()
            ->validate()
                ->always(
                    /**
                     * @param array<string, array{levels: list<mixed>, flags: list<mixed>, label: string}> $modules
                     *
                     * @return array<string, array{levels: list<mixed>, flags: list<mixed>, label: string}>
                     */
                    static function (array $modules): array {
                        foreach ($modules as $key => $module) {
                            if (preg_match(self::KEY_PATTERN, $key) !== 1) {
                                throw new InvalidConfigurationException(sprintf('Invalid module key "%s" at "nowo_api_token.modules": expected ^[a-z][a-z0-9_]{0,31}$.', $key));
                            }
                            foreach ([...$module['levels'], ...$module['flags']] as $name) {
                                if (!is_string($name) || preg_match(self::KEY_PATTERN, $name) !== 1) {
                                    throw new InvalidConfigurationException(sprintf('Invalid level or flag in "nowo_api_token.modules.%s": every name must match ^[a-z][a-z0-9_]{0,31}$.', $key));
                                }
                            }
                            if (count(array_unique($module['levels'])) !== count($module['levels'])) {
                                throw new InvalidConfigurationException(sprintf('Duplicate level in "nowo_api_token.modules.%s.levels".', $key));
                            }
                        }

                        return $modules;
                    },
                )
            ->end();

        return $node;
    }

    /**
     * `expiry`: tokens always expire (Q-O5), `default_days` and every choice within `max_days` (≤ 365).
     */
    private function expiry(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('expiry');
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->integerNode('default_days')->defaultValue(90)->min(1)->max(self::MAX_TTL_DAYS)->end()
                ->integerNode('max_days')->defaultValue(self::MAX_TTL_DAYS)->min(1)->max(self::MAX_TTL_DAYS)->end()
                ->arrayNode('choices')
                    ->requiresAtLeastOneElement()
                    ->defaultValue([7, 30, 90, 365])
                    ->integerPrototype()->min(1)->max(self::MAX_TTL_DAYS)->end()
                ->end()
                ->integerNode('purge_after_days')->defaultValue(30)->min(0)->max(3650)->end()
                ->scalarNode('notify_before_days')->defaultNull()->info('Days before expiry of the ApiTokenExpiringEvent; null = no notice')
                    ->validate()
                        ->ifTrue(static fn (mixed $value): bool => $value !== null && (!is_int($value) || $value < 1 || $value > self::MAX_TTL_DAYS))
                        ->thenInvalid('Expected null or a number of days between 1 and 365, got %s.')
                    ->end()
                ->end()
            ->end()
            ->validate()
                ->always(
                    /**
                     * @param array{default_days: int, max_days: int, choices: list<int>, purge_after_days: int, notify_before_days: int|null} $expiry
                     *
                     * @return array{default_days: int, max_days: int, choices: list<int>, purge_after_days: int, notify_before_days: int|null}
                     */
                    static function (array $expiry): array {
                        foreach ([$expiry['default_days'], ...$expiry['choices']] as $days) {
                            if ($days > $expiry['max_days']) {
                                throw new InvalidConfigurationException(sprintf('"nowo_api_token.expiry": %d days is above max_days (%d).', $days, $expiry['max_days']));
                            }
                        }

                        return $expiry;
                    },
                )
            ->end();

        return $node;
    }
}
