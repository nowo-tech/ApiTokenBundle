<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Integration\Kernel;

use Closure;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\ApiTokenBundle\NowoApiTokenBundle;
use Nowo\ApiTokenBundle\Tests\Fixtures\AuthEchoController;
use Nowo\ApiTokenBundle\Tests\Fixtures\EchoController;
use Nowo\ApiTokenBundle\Tests\Fixtures\ThrowingController;
use Nowo\ApiTokenBundle\Tests\Fixtures\WhoAmIController;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ReferenceConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function dirname;
use function sys_get_temp_dir;

/**
 * Standard-host kernel of the integration tests: FrameworkBundle, SecurityBundle, the bundle and, on demand, DoctrineBundle (DBAL
 * only, or what `$configure` sets up: ORM, host fixtures). `/echo` answers the request's `X-Test-Owner` header and the
 * bundle's API prefix parameter; `/auth` (with `$authRoute`) authenticates the Authorization header; `/api/whoami` and `/open/whoami` (with
 * `$whoamiRoute`) answer what the S2 firewall stamped and who the token storage holds.
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
        /** @var list<CompilerPassInterface> host compiler passes (e.g. UserKit's account-status wiring) */
        private readonly array $passes = [],
        private readonly bool $whoamiRoute = false,
        /** S3 pages: TwigBundle, UiKit, FormKit, forms, CSRF, sessions and the bundle's page routes */
        private readonly bool $ui = false,
    ) {
        parent::__construct('test', false);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        if ($this->configure instanceof Closure) {
            // Host fixtures configure security (a firewall and its user checker, audit LW-M1).
            yield new SecurityBundle();
        }
        if ($this->databaseUrl !== null) {
            yield new DoctrineBundle();
        }
        if ($this->ui) {
            yield new TwigBundle();
            yield new NowoUiKitBundle();
            yield new NowoFormKitBundle();
        }
        yield new NowoApiTokenBundle();
    }

    protected function build(ContainerBuilder $container): void
    {
        foreach ($this->passes as $pass) {
            $container->addCompilerPass($pass);
        }
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
        if ($this->ui) {
            $container->extension('framework', [
                'form'            => ['enabled' => true],
                'csrf_protection' => ['enabled' => true],
                'session'         => ['storage_factory_id' => 'session.storage.factory.mock_file'],
                'validation'      => ['enabled' => true],
                'translator'      => ['fallbacks' => ['en']],
                'default_locale'  => 'en',
                'assets'          => ['enabled' => true],
            ]);
            $container->extension('twig', ['strict_variables' => true]);
        }
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
        if ($this->whoamiRoute) {
            $container->services()
                ->set(WhoAmIController::class)
                ->arg('$tokenStorage', new ReferenceConfigurator('security.token_storage'))
                ->public()
                ->tag('controller.service_arguments');
            $container->services()->set(ThrowingController::class)->public()->tag('controller.service_arguments');
        }
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
        if ($this->ui) {
            $routes->import('.', 'nowo_api_token');
        }
        if ($this->whoamiRoute) {
            $routes->add('api_whoami', '/api/whoami')->controller(WhoAmIController::class)->methods(['GET', 'POST']);
            $routes->add('open_whoami', '/open/whoami')->controller(WhoAmIController::class)->methods(['GET']);
            // Never read the token storage (a lazy firewall stays unauthenticated); `/main` is for a host's own firewall.
            $routes->add('api_echo', '/api/echo')->controller(EchoController::class)->methods(['GET']);
            $routes->add('main_echo', '/main/echo')->controller(EchoController::class)->methods(['GET']);
            $routes->add('main_whoami', '/main/whoami')->controller(WhoAmIController::class)->methods(['GET']);
            $routes->add('api_error', '/api/error')->controller(ThrowingController::class)->methods(['GET']);
        }
    }
}
