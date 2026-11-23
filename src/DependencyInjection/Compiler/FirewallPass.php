<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\ApiTokenBundle\DependencyInjection\NowoApiTokenExtension;
use Nowo\ApiTokenBundle\Infrastructure\Security\ApiTokenFirewallAuthenticator;
use Nowo\ApiTokenBundle\Infrastructure\Security\ApiTokenTokenStorageResetListener;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function in_array;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Build-time checks of the S2 firewall integration (contract §6.3, §6.7), only while the bundle is enabled:
 *
 * - the rate limiter cache pool (`rate_limit.cache_pool`) exists: the limits are never kept in process memory;
 * - without SecurityBundle, the token storage reset listener is removed (there is no token storage to reset);
 * - every firewall that lists {@see ApiTokenFirewallAuthenticator} in `custom_authenticators` is stateless: a stateful
 *   firewall would store the token-authenticated user in a session and hand the token's holder a web session.
 */
final class FirewallPass implements CompilerPassInterface
{
    /** Position of `stateless` in SecurityBundle's `security.firewall.map.config.<name>` (FirewallConfig). */
    private const ARGUMENT_STATELESS = 4;

    /** Position of the authenticator keys in the same definition. */
    private const ARGUMENT_AUTHENTICATORS = 10;

    /**
     * Runs the checks.
     */
    public function process(ContainerBuilder $container): void
    {
        if ($container->getParameter(NowoApiTokenExtension::ALIAS . '.enabled') !== true) {
            return;
        }
        $pool = $container->getParameter(NowoApiTokenExtension::ALIAS . '.rate_limit_pool');
        if (!is_string($pool) || !$container->has($pool)) {
            throw new LogicException(sprintf('nowo_api_token: the rate limiter cache pool "%s" (rate_limit.cache_pool) does not exist. Install symfony/rate-limiter with FrameworkBundle (it defines "cache.rate_limiter"), or point rate_limit.cache_pool at a shared cache pool.', is_string($pool) ? $pool : ''));
        }

        if (!$container->has(ApiTokenTokenStorageResetListener::TOKEN_STORAGE)) {
            $container->removeDefinition(ApiTokenTokenStorageResetListener::class);
        }

        $firewalls = $container->hasParameter('security.firewalls') ? $container->getParameter('security.firewalls') : [];
        foreach (is_array($firewalls) ? $firewalls : [] as $firewall) {
            $id = 'security.firewall.map.config.' . (is_string($firewall) ? $firewall : '');
            if (!is_string($firewall) || !$container->hasDefinition($id)) {
                continue;
            }
            $arguments      = $container->getDefinition($id)->getArguments();
            $authenticators = $arguments['index_' . self::ARGUMENT_AUTHENTICATORS] ?? $arguments[self::ARGUMENT_AUTHENTICATORS] ?? [];
            $stateless      = $arguments['index_' . self::ARGUMENT_STATELESS] ?? $arguments[self::ARGUMENT_STATELESS] ?? false;
            if (is_array($authenticators) && in_array(ApiTokenFirewallAuthenticator::class, $authenticators, true) && $stateless !== true) {
                throw new LogicException(sprintf('nowo_api_token: the firewall "%s" uses %s but is not stateless. Set security.firewalls.%s.stateless: true (a stateful firewall would turn a token into a web session).', $firewall, ApiTokenFirewallAuthenticator::class, $firewall));
            }
        }
    }
}
