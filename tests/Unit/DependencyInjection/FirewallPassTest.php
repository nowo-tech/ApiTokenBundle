<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Unit\DependencyInjection;

use LogicException;
use Nowo\ApiTokenBundle\DependencyInjection\Compiler\FirewallPass;
use Nowo\ApiTokenBundle\Infrastructure\Security\ApiTokenFirewallAuthenticator;
use Nowo\ApiTokenBundle\Infrastructure\Security\ApiTokenTokenStorageResetListener;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Build-time checks of the firewall integration (contract §6.3, §6.7).
 */
final class FirewallPassTest extends TestCase
{
    public function testStatelessFirewallsAndOtherFirewallsBuild(): void
    {
        $container = $this->container();
        $container->setParameter('security.firewalls', ['dev', 'api', 'main', 'gone', 42]);
        $container->setDefinition('security.firewall.map.config.dev', $this->firewall(false, []));
        $container->setDefinition('security.firewall.map.config.api', $this->firewall(true, ['http_basic', ApiTokenFirewallAuthenticator::class]));
        $container->setDefinition('security.firewall.map.config.main', (new Definition(stdClass::class))->setArguments([4 => false, 10 => ['form_login']]));

        (new FirewallPass())->process($container);

        self::assertTrue($container->hasDefinition(ApiTokenTokenStorageResetListener::class));
    }

    public function testAStatefulFirewallWithTheAuthenticatorDoesNotBuild(): void
    {
        $container = $this->container();
        $container->setParameter('security.firewalls', ['api']);
        $container->setDefinition('security.firewall.map.config.api', (new Definition(stdClass::class))->setArguments([4 => false, 10 => [ApiTokenFirewallAuthenticator::class]]));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('the firewall "api" uses');
        (new FirewallPass())->process($container);
    }

    public function testAMissingCachePoolDoesNotBuild(): void
    {
        $container = $this->container('cache.missing');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"cache.missing" (rate_limit.cache_pool) does not exist');
        (new FirewallPass())->process($container);
    }

    public function testWithoutSecurityBundleTheResetListenerIsRemoved(): void
    {
        $container = $this->container();
        $container->removeDefinition(ApiTokenTokenStorageResetListener::TOKEN_STORAGE);

        (new FirewallPass())->process($container);

        self::assertFalse($container->hasDefinition(ApiTokenTokenStorageResetListener::class));
    }

    public function testADisabledBundleIsNotChecked(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('nowo_api_token.enabled', false);

        (new FirewallPass())->process($container);

        self::assertFalse($container->hasDefinition(ApiTokenTokenStorageResetListener::class));
    }

    private function container(string $pool = 'cache.rate_limiter'): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('nowo_api_token.enabled', true);
        $container->setParameter('nowo_api_token.rate_limit_pool', $pool);
        $container->register('cache.rate_limiter', stdClass::class);
        $container->register(ApiTokenTokenStorageResetListener::TOKEN_STORAGE, stdClass::class);
        $container->register(ApiTokenTokenStorageResetListener::class, ApiTokenTokenStorageResetListener::class);

        return $container;
    }

    /**
     * SecurityBundle's shape: a child of `security.firewall.config` with replaced arguments.
     *
     * @param list<string> $authenticators
     */
    private function firewall(bool $stateless, array $authenticators): ChildDefinition
    {
        return (new ChildDefinition('security.firewall.config'))->replaceArgument(4, $stateless)->replaceArgument(10, $authenticators);
    }
}
