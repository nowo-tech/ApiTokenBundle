<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Integration\Kernel;

use Symfony\Component\DependencyInjection\Container;

use function str_starts_with;

/**
 * Standard Symfony host: the bundle boots with zero configuration and registers nothing in S0.
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

    public function testEnablingTheBundleInS0StillRegistersNoService(): void
    {
        $container = $this->bootKernel(['enabled' => true, 'api' => ['prefix' => '/v-api']])->getContainer();

        self::assertTrue($container->getParameter('nowo_api_token.enabled'));
        self::assertSame('/v-api', $container->getParameter('nowo_api_token.api_prefix'));
        self::assertSame([], $this->bundleServiceIds($container));
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
