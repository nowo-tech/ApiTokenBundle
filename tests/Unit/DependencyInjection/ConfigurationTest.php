<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Unit\DependencyInjection;

use Nowo\ApiTokenBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    public function testDefaultsMatchTheContract(): void
    {
        $config = $this->process([]);

        self::assertFalse($config['enabled']);
        self::assertSame('nwt_', $config['token_prefix']);
        self::assertTrue($config['checksum']);
        self::assertSame(['version' => 1, 'pepper' => null], $config['hash']);
        self::assertSame(['entity_manager' => 'default', 'table_prefix' => 'nowo_', 'auto_mapping' => true, 'mapping' => 'xml'], $config['doctrine']);
        self::assertSame(['type' => 'user', 'class' => null, 'id_field' => 'id', 'resolver' => null, 'status' => null, 'user_checker' => null], $config['owner']);
        self::assertSame([], $config['modules']);
        self::assertSame(['task_board' => 'auto', 'wiki' => 'auto', 'mcp_kit' => 'auto', 'time_track' => false, 'http_log' => 'auto', 'user_kit' => 'auto', 'audit_kit' => 'auto', 'auth_kit' => 'auto'], $config['bridges']);
        self::assertSame(['allow_everything' => true, 'provider' => null, 'max_entries' => 200], $config['reach']);
        self::assertSame(['default_days' => 90, 'max_days' => 365, 'choices' => [7, 30, 90, 365], 'purge_after_days' => 30, 'notify_before_days' => null], $config['expiry']);
        self::assertSame(20, $config['max_per_owner']);
        self::assertSame(['enabled' => true, 'fresh_seconds' => 300, 'max_attempts' => 5, 'gate' => null], $config['reauth']);
        self::assertSame([
            'cache_pool'        => 'cache.rate_limiter',
            'per_token'         => ['limit' => 100, 'interval' => '1 minute'],
            'writes'            => ['limit' => 30, 'interval' => '1 minute'],
            'count_rest_writes' => false,
            'failed_per_ip'     => ['limit' => 20, 'interval' => '15 minutes'],
            'issue_per_owner'   => ['limit' => 10, 'interval' => '1 hour'],
        ], $config['rate_limit']);
        self::assertSame(['on_failure' => 'pass'], $config['firewall']);
        self::assertSame(['beacon_v1' => false], $config['legacy']);
        self::assertSame(['erase_usage_on_revoke' => false], $config['http_log']);
        self::assertSame(['enabled' => false, 'purge_expired' => '20 3 * * *', 'notify_expiring' => '0 8 * * *'], $config['schedule']);
        self::assertSame(['max_seconds' => 50, 'batch_size' => 500], $config['messenger']);
        self::assertSame([], $config['revoke_on_sessions_revoked']);
        self::assertSame(['enabled' => false, 'prefix' => '/api', 'revocation_endpoint' => false], $config['api']);
        self::assertSame(['enabled' => false, 'path_prefix' => '/account/security/api-tokens', 'layout_template' => null, 'templates' => ['list' => null, 'created' => null]], $config['web_ui']);
        self::assertSame(['enabled' => false, 'path_prefix' => '/admin/api-tokens', 'security' => ['access_roles' => ['ROLE_ADMIN'], 'access_checker' => null, 'allow_unauthenticated' => false]], $config['admin_ui']);
        self::assertNull($config['settings_provider']);
    }

    public function testAFullHostConfigurationIsAccepted(): void
    {
        $config = $this->process([
            'enabled'      => true,
            'token_prefix' => 'bt_',
            'hash'         => ['version' => 2, 'pepper' => 'from-env'],
            'doctrine'     => ['entity_manager' => 'tokens', 'table_prefix' => '', 'auto_mapping' => false, 'mapping' => 'attribute'],
            'owner'        => ['type' => 'member', 'resolver' => 'app.owner_resolver', 'status' => 'app.owner_status'],
            'modules'      => [
                'tasks' => ['levels' => ['my_tasks', 'read', 'tasks', 'full'], 'label' => 'Tasks'],
                'mcp'   => ['levels' => ['on'], 'flags' => ['destructive']],
            ],
            'bridges'                    => ['wiki' => true, 'time_track' => 'auto', 'auth_kit' => false],
            'expiry'                     => ['default_days' => 30, 'max_days' => 90, 'choices' => [7, 30, 90], 'notify_before_days' => 7],
            'rate_limit'                 => ['per_token' => ['limit' => 50, 'interval' => '2 minutes'], 'failed_per_ip' => ['interval' => '1 hour']],
            'firewall'                   => ['on_failure' => 'unauthorized'],
            'schedule'                   => ['enabled' => true, 'purge_expired' => '*/30 1-5 * * 1,3'],
            'revoke_on_sessions_revoked' => ['account_disabled'],
            'api'                        => ['enabled' => true, 'prefix' => '/v-api', 'revocation_endpoint' => true],
            'web_ui'                     => ['enabled' => true, 'path_prefix' => '/me/tokens'],
            'admin_ui'                   => ['enabled' => true, 'security' => ['access_roles' => ['ROLE_OPS'], 'access_checker' => 'app.checker']],
            'settings_provider'          => 'app.settings',
        ]);

        self::assertSame(['tasks' => ['levels' => ['my_tasks', 'read', 'tasks', 'full'], 'label' => 'Tasks', 'flags' => []], 'mcp' => ['levels' => ['on'], 'flags' => ['destructive'], 'label' => '']], $config['modules']);
        self::assertTrue($config['bridges']['wiki']);
        self::assertSame('auto', $config['bridges']['time_track']);
        self::assertSame(7, $config['expiry']['notify_before_days']);
        self::assertSame('/v-api', $config['api']['prefix']);
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('invalidConfigurations')]
    public function testInvalidValuesAreRefusedWithThePath(array $config, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        $this->process($config);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidConfigurations(): iterable
    {
        yield 'unknown key' => [['tokens' => []], 'Unrecognized option "tokens"'];
        yield 'token prefix case' => [['token_prefix' => 'NWT_'], 'nowo_api_token.token_prefix'];
        yield 'pepper missing' => [['hash' => ['version' => 2]], 'hash.version 2 needs a non-empty hash.pepper'];
        yield 'hash version' => [['hash' => ['version' => 3]], 'nowo_api_token.hash.version'];
        yield 'table prefix' => [['doctrine' => ['table_prefix' => 'Bad-']], 'nowo_api_token.doctrine.table_prefix'];
        yield 'mapping' => [['doctrine' => ['mapping' => 'yaml']], 'nowo_api_token.doctrine.mapping'];
        yield 'owner type' => [['owner' => ['type' => 'User']], 'nowo_api_token.owner.type'];
        yield 'empty service id' => [['owner' => ['resolver' => '']], 'Expected a service id or null'];
        yield 'tri-state' => [['bridges' => ['wiki' => 'yes']], 'Expected true, false or "auto"'];
        yield 'module key' => [['modules' => ['Tasks' => ['levels' => ['read']]]], 'Invalid module key "Tasks"'];
        yield 'module level' => [['modules' => ['tasks' => ['levels' => ['Read']]]], 'Invalid level or flag in "nowo_api_token.modules.tasks"'];
        yield 'module flag' => [['modules' => ['mcp' => ['levels' => ['on'], 'flags' => ['Delete!']]]], 'Invalid level or flag in "nowo_api_token.modules.mcp"'];
        yield 'duplicate level' => [['modules' => ['tasks' => ['levels' => ['read', 'read']]]], 'Duplicate level in "nowo_api_token.modules.tasks.levels"'];
        yield 'module without levels' => [['modules' => ['tasks' => ['levels' => []]]], 'nowo_api_token.modules.tasks.levels'];
        yield 'max ttl above 365' => [['expiry' => ['max_days' => 400]], 'nowo_api_token.expiry.max_days'];
        yield 'default above max' => [['expiry' => ['max_days' => 30]], '90 days is above max_days (30)'];
        yield 'choice above max' => [['expiry' => ['default_days' => 7, 'max_days' => 30, 'choices' => [7, 90]]], '90 days is above max_days (30)'];
        yield 'notice days' => [['expiry' => ['notify_before_days' => 0]], 'Expected null or a number of days'];
        yield 'rate interval' => [['rate_limit' => ['writes' => ['interval' => '1 fortnight']]], 'Expected an interval such as "1 minute"'];
        yield 'rate limit' => [['rate_limit' => ['writes' => ['limit' => 0]]], 'nowo_api_token.rate_limit.writes.limit'];
        yield 'cron' => [['schedule' => ['notify_expiring' => 'every day']], 'Expected a five-field cron expression'];
        yield 'on failure' => [['firewall' => ['on_failure' => 'deny']], 'nowo_api_token.firewall.on_failure'];
        yield 'api prefix' => [['api' => ['prefix' => '/api/']], 'Expected an absolute path without a trailing slash'];
        yield 'ui path' => [['web_ui' => ['path_prefix' => 'account']], 'Expected an absolute path without a trailing slash'];
        yield 'admin roles' => [['admin_ui' => ['security' => ['access_roles' => []]]], 'nowo_api_token.admin_ui.security.access_roles'];
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function process(array $config): array
    {
        return (new Processor())->processConfiguration(new Configuration(), [$config]);
    }
}
