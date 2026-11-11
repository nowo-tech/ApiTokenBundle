<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Unit\DependencyInjection;

use Nowo\ApiTokenBundle\Application\Default\ConfigApiTokenPolicy;
use Nowo\ApiTokenBundle\Contract\ApiTokenPolicyInterface;
use Nowo\ApiTokenBundle\Contract\ApiTokenSettingsProviderInterface;
use Nowo\ApiTokenBundle\Contract\ModuleDefinitionProviderInterface;
use Nowo\ApiTokenBundle\Contract\OwnerResolverInterface;
use Nowo\ApiTokenBundle\Contract\OwnerStatusInterface;
use Nowo\ApiTokenBundle\Contract\ReachProviderInterface;
use Nowo\ApiTokenBundle\Contract\TokenRevocationListenerInterface;
use Nowo\ApiTokenBundle\DependencyInjection\NowoApiTokenExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

use function array_filter;
use function array_keys;
use function array_values;
use function count;
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
            ['enabled' => true, 'owner' => ['resolver' => 'app.owner_resolver'], 'api' => ['enabled' => true, 'prefix' => '/api/v-host'], 'doctrine' => ['table_prefix' => 'app_']],
        ], $container);

        self::assertTrue($container->getParameter('nowo_api_token.enabled'));
        self::assertSame('/api/v-host', $container->getParameter('nowo_api_token.api_prefix'));
        self::assertSame('app_', $container->getParameter('nowo_api_token.table_prefix'));
    }

    public function testThePepperNeverSitsInAParameter(): void
    {
        $container = new ContainerBuilder();
        (new NowoApiTokenExtension())->load([['hash' => ['version' => 2, 'pepper' => 'placeholder-not-a-secret']]], $container);

        $config = $container->getParameter('nowo_api_token.config');
        self::assertIsArray($config);
        self::assertSame(['version' => 2, 'pepper' => '[redacted]'], $config['hash'] ?? null);
    }

    public function testHostServicesReplaceTheDefaults(): void
    {
        $container = new ContainerBuilder();
        (new NowoApiTokenExtension())->load([[
            'enabled'           => true,
            'owner'             => ['resolver' => 'app.owner_resolver', 'status' => 'app.owner_status'],
            'reach'             => ['provider' => 'app.reach'],
            'settings_provider' => 'app.settings',
        ]], $container);

        self::assertSame('app.owner_resolver', (string) $container->getAlias(OwnerResolverInterface::class));
        self::assertSame('app.owner_status', (string) $container->getAlias(OwnerStatusInterface::class));
        self::assertSame('app.reach', (string) $container->getAlias(ReachProviderInterface::class));
        self::assertSame('app.settings', (string) $container->getAlias(ApiTokenSettingsProviderInterface::class));
        self::assertSame(ConfigApiTokenPolicy::class, (string) $container->getAlias(ApiTokenPolicyInterface::class));
        self::assertTrue($container->getAlias(ApiTokenPolicyInterface::class)->isPublic());
        self::assertArrayHasKey(TokenRevocationListenerInterface::class, $container->getAutoconfiguredInstanceof());
        self::assertArrayHasKey(ModuleDefinitionProviderInterface::class, $container->getAutoconfiguredInstanceof());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<array<string, mixed>>, array<string, mixed>|null}>
     */
    public static function prependCases(): iterable
    {
        $orm = [['orm' => ['auto_mapping' => true]]];
        yield 'xml by default' => [['enabled' => true], $orm, ['type' => 'xml', 'prefix' => 'Nowo\\ApiTokenBundle\\Domain', 'is_bundle' => false]];
        yield 'attribute twin' => [['enabled' => true, 'doctrine' => ['mapping' => 'attribute', 'entity_manager' => 'tokens']], $orm, ['type' => 'attribute', 'prefix' => 'Nowo\\ApiTokenBundle\\Infrastructure\\Doctrine\\Entity', 'is_bundle' => false]];
        yield 'disabled' => [['enabled' => false], $orm, null];
        yield 'DDD host maps it itself' => [['enabled' => true, 'doctrine' => ['auto_mapping' => false]], $orm, null];
        yield 'DBAL only' => [['enabled' => true], [['dbal' => ['url' => 'sqlite:///:memory:']]], null];
    }

    /**
     * @param array<string, mixed> $config
     * @param list<array<string, mixed>> $doctrine
     * @param array<string, mixed>|null $expected
     */
    #[DataProvider('prependCases')]
    public function testPrependRegistersTheMappingOnlyWhenItShould(array $config, array $doctrine, ?array $expected): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new NowoApiTokenExtension());
        $container->registerExtension(new class extends Extension {
            public function getAlias(): string
            {
                return 'doctrine';
            }

            public function load(array $configs, ContainerBuilder $container): void
            {
            }
        });
        $container->loadFromExtension('nowo_api_token', $config);
        foreach ($doctrine as $piece) {
            $container->loadFromExtension('doctrine', $piece);
        }
        (new NowoApiTokenExtension())->prepend($container);

        $configs = $container->getExtensionConfig('doctrine');
        if ($expected === null) {
            self::assertCount(count($doctrine), $configs);

            return;
        }
        $em      = $config['doctrine']['entity_manager'] ?? 'default';
        $mapping = $configs[0]['orm']['entity_managers'][$em]['mappings']['NowoApiTokenBundle'] ?? null;
        self::assertIsArray($mapping);
        foreach ($expected as $key => $value) {
            self::assertSame($value, $mapping[$key]);
        }
        self::assertDirectoryExists($mapping['dir']);
    }

    public function testPrependWithoutDoctrineDoesNothing(): void
    {
        $container = new ContainerBuilder();
        (new NowoApiTokenExtension())->prepend($container);
        self::assertSame([], $container->getExtensionConfig('doctrine'));
    }
}
