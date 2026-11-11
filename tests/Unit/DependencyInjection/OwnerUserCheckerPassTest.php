<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Unit\DependencyInjection;

use LogicException;
use Nowo\ApiTokenBundle\DependencyInjection\Compiler\OwnerUserCheckerPass;
use Nowo\ApiTokenBundle\Infrastructure\Owner\UserCheckerOwnerStatus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Audit LW-M1: which user checkers the default owner status runs.
 */
final class OwnerUserCheckerPassTest extends TestCase
{
    public function testFirewallCheckersAreChainedOncePerTarget(): void
    {
        $container = $this->container(null);
        $container->setParameter('security.firewalls', ['dev', 'main', 'api', 'admin', 'stock', 42]);
        $container->setAlias('security.user_checker.main', 'app.checker');
        $container->setAlias('security.user_checker.api', 'app.checker');
        $container->setAlias('security.user_checker.admin', 'security.user_checker.chain.admin');
        $container->setAlias('security.user_checker.stock', 'security.user_checker');

        (new OwnerUserCheckerPass())->process($container);

        self::assertEquals(
            [new Reference('security.user_checker.main'), new Reference('security.user_checker.admin')],
            $container->getDefinition(UserCheckerOwnerStatus::class)->getArgument(0),
        );
    }

    public function testExplicitCheckerAndNone(): void
    {
        $explicit = $this->container('app.checker');
        (new OwnerUserCheckerPass())->process($explicit);
        self::assertEquals([new Reference('app.checker')], $explicit->getDefinition(UserCheckerOwnerStatus::class)->getArgument(0));

        $none = $this->container(OwnerUserCheckerPass::NONE);
        (new OwnerUserCheckerPass())->process($none);
        self::assertSame([], $none->getDefinition(UserCheckerOwnerStatus::class)->getArgument(0));
    }

    public function testNothingToDo(): void
    {
        $disabled = new ContainerBuilder();
        (new OwnerUserCheckerPass())->process($disabled);
        self::assertFalse($disabled->hasDefinition(UserCheckerOwnerStatus::class));

        $custom = $this->container(null, true);
        (new OwnerUserCheckerPass())->process($custom);
        self::assertSame(['untouched'], $custom->getDefinition(UserCheckerOwnerStatus::class)->getArgument(0));
    }

    public function testNoHostCheckerRefusesToBuild(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('no firewall has a user checker');
        (new OwnerUserCheckerPass())->process($this->container(null));
    }

    private function container(?string $userChecker, bool $custom = false): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('nowo_api_token.owner_status', ['custom' => $custom, 'user_checker' => $userChecker]);
        $container->register(UserCheckerOwnerStatus::class, UserCheckerOwnerStatus::class)->setArguments([['untouched']]);

        return $container;
    }
}
