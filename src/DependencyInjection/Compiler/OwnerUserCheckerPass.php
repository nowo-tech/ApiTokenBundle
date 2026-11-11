<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\ApiTokenBundle\DependencyInjection\NowoApiTokenExtension;
use Nowo\ApiTokenBundle\Infrastructure\Owner\UserCheckerOwnerStatus;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function is_array;
use function is_string;

/**
 * Chooses the user checkers of the default owner status (audit LW-M1). SecurityBundle keeps the global
 * `security.user_checker` as its stock `InMemoryUserChecker` and wires a host's checker per firewall
 * (`security.user_checker.<firewall>`), so the global id never runs the host's account rules.
 *
 * - `owner.user_checker: <service id>`: that checker only;
 * - `owner.user_checker: none`: no checker (the host accepts that the resolver alone decides);
 * - `owner.user_checker: null` (default): the checker of every firewall that has one other than the stock id
 *   (UserKit's account-status chain included); the build fails when there is none, so a disabled account can never
 *   keep its API access silently.
 *
 * Runs after the other bundles' passes (priority -1000), which may re-point the firewall aliases.
 */
final class OwnerUserCheckerPass implements CompilerPassInterface
{
    /** `owner.user_checker` value that disables the checkers. */
    public const NONE = 'none';

    /** SecurityBundle's global checker (`InMemoryUserChecker`). */
    private const STOCK = 'security.user_checker';

    /**
     * Sets the checkers argument of {@see UserCheckerOwnerStatus}.
     */
    public function process(ContainerBuilder $container): void
    {
        $parameter = NowoApiTokenExtension::ALIAS . '.owner_status';
        if (!$container->hasParameter($parameter) || !$container->hasDefinition(UserCheckerOwnerStatus::class)) {
            return;
        }
        /** @var array{custom: bool, user_checker: string|null} $config */
        $config = $container->getParameter($parameter);
        if ($config['custom']) {
            return;
        }

        $configured = $config['user_checker'];
        $checkers   = match (true) {
            $configured === self::NONE => [],
            $configured !== null       => [new Reference($configured)],
            default                    => $this->firewallCheckers($container),
        };
        if ($configured === null && $checkers === []) {
            throw new LogicException('nowo_api_token: no firewall has a user checker, so disabled or locked accounts would keep their API tokens. Set security.firewalls.<name>.user_checker, or nowo_api_token.owner.user_checker (a UserCheckerInterface service id, or "none" to let the owner resolver alone decide), or owner.status.');
        }
        $container->getDefinition(UserCheckerOwnerStatus::class)->setArgument(0, $checkers);
    }

    /**
     * @return list<Reference>
     */
    private function firewallCheckers(ContainerBuilder $container): array
    {
        $firewalls = $container->hasParameter('security.firewalls') ? $container->getParameter('security.firewalls') : [];
        $checkers  = [];
        $seen      = [];
        foreach (is_array($firewalls) ? $firewalls : [] as $firewall) {
            $alias = 'security.user_checker.' . (is_string($firewall) ? $firewall : '');
            if (!is_string($firewall) || !$container->hasAlias($alias)) {
                continue;
            }
            $target = (string) $container->getAlias($alias);
            if ($target === self::STOCK || isset($seen[$target])) {
                continue;
            }
            $seen[$target] = true;
            $checkers[]    = new Reference($alias);
        }

        return $checkers;
    }
}
