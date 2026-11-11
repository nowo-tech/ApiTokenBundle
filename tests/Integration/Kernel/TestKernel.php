<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Integration\Kernel;

use Closure;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\ApiTokenBundle\NowoApiTokenBundle;
use Nowo\ApiTokenBundle\Tests\Fixtures\AuthEchoController;
use Nowo\ApiTokenBundle\Tests\Fixtures\EchoController;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function dirname;
use function sys_get_temp_dir;

/**
 * Standard-host kernel of the integration tests: FrameworkBundle, the bundle and, on demand, DoctrineBundle (DBAL
 * only, or what `$configure` sets up: ORM, host fixtures). `/echo` answers the request's `X-Test-Owner` header and the
 * bundle's API prefix parameter; `/auth` (with `$authRoute`) authenticates the Authorization header.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed> $bundleConfig `nowo_api_token` configuration
     * @param string|null $databaseUrl DBAL URL; null = no DoctrineBundle
     * @param (Closure(ContainerConfigurator, string): void)|null $configure extra host configuration
     */
    public function __construct(
        private readonly string $id,
        private readonly array $bundleConfig = [],
        private readonly ?string $databaseUrl = null,
        private readonly ?Closure $configure = null,
        private readonly bool $authRoute = false,
    ) {
        parent::__construct('test', false);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        if ($this->databaseUrl !== null) {
            yield new DoctrineBundle();
        }
        yield new NowoApiTokenBundle();
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 3);
    }

    public function getCacheDir(): string
    {
        return self::baseDir() . '/' . $this->id . '/cache';
    }

    public function getLogDir(): string
    {
        return self::baseDir() . '/' . $this->id . '/log';
    }

    public static function baseDir(): string
    {
        return sys_get_temp_dir() . '/nowo-api-token-tests';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'test'                  => true,
            'secret'                => 'S0-' . $this->id,
            'http_method_override'  => false,
            'handle_all_throwables' => true,
            'php_errors'            => ['log' => true],
            'router'                => ['utf8' => true],
        ]);
        if ($this->databaseUrl !== null && !$this->configure instanceof Closure) {
            $container->extension('doctrine', ['dbal' => ['url' => $this->databaseUrl]]);
        }
        if ($this->configure instanceof Closure) {
            ($this->configure)($container, (string) $this->databaseUrl);
        }
        $container->extension('nowo_api_token', $this->bundleConfig);
        $container->services()->set('logger', NullLogger::class);
        $container->services()
            ->set(EchoController::class)
            ->arg('$apiPrefix', '%nowo_api_token.api_prefix%')
            ->public()
            ->tag('controller.service_arguments');
        if ($this->authRoute) {
            $container->services()
                ->set(AuthEchoController::class)
                ->autowire()
                ->public()
                ->tag('controller.service_arguments');
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('echo', '/echo')->controller(EchoController::class)->methods(['GET']);
        if ($this->authRoute) {
            $routes->add('auth', '/auth')->controller(AuthEchoController::class)->methods(['GET']);
        }
    }
}
