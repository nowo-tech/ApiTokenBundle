<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Integration\Kernel;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\ApiTokenBundle\Contract\ApiTokenAuthenticatorInterface;
use Nowo\ApiTokenBundle\Contract\ApiTokenIssuerInterface;
use Nowo\ApiTokenBundle\Contract\ApiTokenQueryInterface;
use Nowo\ApiTokenBundle\Contract\ApiTokenRevokerInterface;
use Nowo\ApiTokenBundle\Tests\Fixtures\Standard\User;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function dirname;
use function str_starts_with;

/**
 * Standard Symfony host: zero configuration registers nothing; `enabled: true` registers the S1 core.
 */
final class KernelBootTest extends KernelTestCase
{
    public function testTheBundleBootsWithZeroConfigurationAndIsDisabled(): void
    {
        $container = $this->bootKernel()->getContainer();

        self::assertFalse($container->getParameter('nowo_api_token.enabled'));
        self::assertSame('/api', $container->getParameter('nowo_api_token.api_prefix'));
        self::assertSame('nowo_', $container->getParameter('nowo_api_token.table_prefix'));
        self::assertSame([], $this->bundleServiceIds($container));
    }

    public function testADisabledBundleAddsNoMappingToAnAutoMappedHost(): void
    {
        $container = $this->bootKernel([], 'sqlite:///:memory:', static function (ContainerConfigurator $c, string $url): void {
            $c->extension('doctrine', ['dbal' => ['url' => $url], 'orm' => ['auto_mapping' => true, 'mappings' => ['Fixtures' => ['type' => 'attribute', 'dir' => dirname(__DIR__, 2) . '/Fixtures/Standard', 'prefix' => 'Nowo\ApiTokenBundle\Tests\Fixtures\Standard', 'is_bundle' => false]]]]);
        })->getContainer();
        $doctrine = $container->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $em = $doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        self::assertSame([User::class], $em->getConfiguration()->getMetadataDriverImpl()?->getAllClassNames());
        self::assertSame([], $this->bundleServiceIds($container));
    }

    public function testEnablingTheBundleRegistersTheCoreServices(): void
    {
        $host      = Host::standard();
        $container = $this->bootKernel($host->bundleConfig(['api' => ['prefix' => '/v-api']]), 'sqlite:///:memory:', $host->configure())->getContainer();

        self::assertTrue($container->getParameter('nowo_api_token.enabled'));
        self::assertSame('/v-api', $container->getParameter('nowo_api_token.api_prefix'));
        foreach ([ApiTokenIssuerInterface::class, ApiTokenRevokerInterface::class, ApiTokenQueryInterface::class, ApiTokenAuthenticatorInterface::class] as $port) {
            self::assertTrue($container->has($port), $port . ' is a public port');
        }
    }

    public function testEnabledNeedsAnOwnerResolver(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('owner.class');
        $this->bootKernel(['enabled' => true], 'sqlite:///:memory:');
    }

    public function testTheOwnerClassMustExist(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('does not exist');
        $this->bootKernel(['enabled' => true, 'owner' => ['class' => 'App\Missing\User']], 'sqlite:///:memory:');
    }

    public function testEnabledNeedsDoctrine(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('DoctrineBundle');
        $this->bootKernel(['enabled' => true, 'owner' => ['class' => User::class]]);
    }

    /**
     * @return list<string>
     */
    private function bundleServiceIds(object $container): array
    {
        self::assertInstanceOf(Container::class, $container);
        $ids = [];
        foreach ($container->getServiceIds() as $id) {
            $bundleService = str_starts_with($id, 'nowo_api_token') || str_starts_with($id, 'Nowo\\ApiTokenBundle\\');
            if ($bundleService && !str_starts_with($id, 'Nowo\\ApiTokenBundle\\Tests\\')) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
