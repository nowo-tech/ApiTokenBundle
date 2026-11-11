<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Integration\Kernel;

use LogicException;
use Nowo\ApiTokenBundle\Contract\OwnerStatusInterface;
use Nowo\ApiTokenBundle\Tests\Fixtures\Ddd\Member;
use Nowo\ApiTokenBundle\Tests\Fixtures\Ddd\MemberStatus;
use Nowo\ApiTokenBundle\Tests\Fixtures\Standard\ActiveUserChecker;
use Nowo\ApiTokenBundle\Tests\Fixtures\Standard\User;
use stdClass;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Audit LW-M1: the default owner status runs the host's real user checker, the one Symfony wires on the firewall
 * (`firewalls.<name>.user_checker`), UserKit's account-status chain or `owner.user_checker`, never SecurityBundle's
 * stock global `security.user_checker`.
 */
final class OwnerStatusWiringTest extends KernelTestCase
{
    private const STOCK_FIREWALL = ['pattern' => '^/api', 'stateless' => true, 'provider' => 'users'];

    public function testTheFirewallCheckerIsUsed(): void
    {
        $status = $this->ownerStatus([]);

        self::assertTrue($status->isActive(new User('a')));
        self::assertFalse($status->isActive(new User('b', false)), 'disabled by the host checker set on the firewall only');
        self::assertFalse($status->isActive(new stdClass()), 'no checker can judge it: fails closed');
    }

    public function testUserKitStyleAccountStatusChainIsUsed(): void
    {
        // What UserKit's AccountStatusUserCheckerPass does: tag its checker into the firewall chain, re-point the alias.
        $userKit = new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $container->getDefinition(ActiveUserChecker::class)->addTag('security.user_checker.api');
                $container->setAlias('security.user_checker.api', 'security.user_checker.chain.api');
            }
        };
        $status = $this->ownerStatus([], self::STOCK_FIREWALL, [$userKit]);

        self::assertTrue($status->isActive(new User('a')));
        self::assertFalse($status->isActive(new User('b', false)));
    }

    public function testAnExplicitCheckerServiceIsUsed(): void
    {
        $status = $this->ownerStatus(['owner' => ['user_checker' => ActiveUserChecker::class]], self::STOCK_FIREWALL);

        self::assertFalse($status->isActive(new User('b', false)));
    }

    public function testOnlyTheStockCheckerRefusesToBuild(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('no firewall has a user checker');
        $this->ownerStatus([], self::STOCK_FIREWALL);
    }

    public function testNoneIsAnExplicitOptOut(): void
    {
        $status = $this->ownerStatus(['owner' => ['user_checker' => 'none']], self::STOCK_FIREWALL);

        self::assertTrue($status->isActive(new User('b', false)), 'the resolver alone decides');
    }

    public function testAHostStatusServiceIsLeftAlone(): void
    {
        $status = $this->ownerStatus(['owner' => ['status' => MemberStatus::class]], self::STOCK_FIREWALL);

        self::assertInstanceOf(MemberStatus::class, $status);
        self::assertFalse($status->isActive(new Member('m', true)));
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed>|null $firewall
     * @param list<CompilerPassInterface> $passes
     */
    private function ownerStatus(array $config, ?array $firewall = null, array $passes = []): OwnerStatusInterface
    {
        $host      = Host::standard();
        $kernel    = $this->bootKernel($host->bundleConfig($config), 'sqlite:///:memory:', $host->configure($firewall), false, $passes);
        $container = $kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);
        $status = $container->get(OwnerStatusInterface::class);
        self::assertInstanceOf(OwnerStatusInterface::class, $status);

        return $status;
    }
}
