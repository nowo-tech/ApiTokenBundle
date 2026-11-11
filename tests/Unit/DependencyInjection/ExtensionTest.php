<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Unit\DependencyInjection;

use Nowo\ApiTokenBundle\DependencyInjection\NowoApiTokenExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function array_filter;
use function array_keys;
use function array_values;
use function str_starts_with;

final class ExtensionTest extends TestCase
{
    public function testDefaultsPublishTheParametersAndRegisterNoService(): void
    {
        $container = new ContainerBuilder();
        (new NowoApiTokenExtension())->load([], $container);

        self::assertFalse($container->getParameter('nowo_api_token.enabled'));
        self::assertSame('/api', $container->getParameter('nowo_api_token.api_prefix'));
        self::assertSame('nowo_', $container->getParameter('nowo_api_token.table_prefix'));
        self::assertIsArray($container->getParameter('nowo_api_token.config'));
        self::assertSame([], array_values(array_filter(
            array_keys($container->getDefinitions()),
            static fn (string $id): bool => !str_starts_with($id, 'service_container'),
        )));
    }

    public function testHostValuesReachTheParameters(): void
    {
        $container = new ContainerBuilder();
        (new NowoApiTokenExtension())->load([
            ['enabled' => true, 'api' => ['enabled' => true, 'prefix' => '/api/v-host'], 'doctrine' => ['table_prefix' => 'app_']],
        ], $container);

        self::assertTrue($container->getParameter('nowo_api_token.enabled'));
        self::assertSame('/api/v-host', $container->getParameter('nowo_api_token.api_prefix'));
        self::assertSame('app_', $container->getParameter('nowo_api_token.table_prefix'));
    }
}
