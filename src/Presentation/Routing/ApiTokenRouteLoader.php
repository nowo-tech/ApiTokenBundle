<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Presentation\Routing;

use Nowo\ApiTokenBundle\Presentation\Controller\AccountTokensController;
use Nowo\ApiTokenBundle\Presentation\Controller\OperatorTokensController;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\RouteCollection;

/**
 * The bundle's page routes (`type: nowo_api_token`): the account pages under `web_ui.path_prefix` when `web_ui.enabled`,
 * the operator page under `admin_ui.path_prefix` when `admin_ui.enabled`. Nothing while both are off. Internal UI
 * routes: not under the API prefix, not versioned (contract §7.1).
 */
final class ApiTokenRouteLoader extends Loader
{
    /** Loader type the host import names. */
    public const TYPE = 'nowo_api_token';

    /**
     * @param array{enabled: bool, path_prefix: string} $webUi
     * @param array{enabled: bool, path_prefix: string} $adminUi
     */
    public function __construct(private readonly array $webUi, private readonly array $adminUi, ?string $env = null)
    {
        parent::__construct($env);
    }

    /**
     * The enabled areas' routes, each attribute path behind its prefix (`/` is the prefix itself).
     */
    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        $routes = new RouteCollection();
        foreach ([[$this->webUi, AccountTokensController::class], [$this->adminUi, OperatorTokensController::class]] as [$area, $controller]) {
            if (!$area['enabled']) {
                continue;
            }
            $loaded = $this->attributeLoader()->load($controller);
            foreach ($loaded->all() as $name => $route) {
                $route->setPath($route->getPath() === '/' ? $area['path_prefix'] : $area['path_prefix'] . $route->getPath());
                $routes->add($name, $route);
            }
            foreach ($loaded->getResources() as $classResource) {
                $routes->addResource($classResource);
            }
        }

        return $routes;
    }

    /**
     * Only the `nowo_api_token` type.
     */
    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === self::TYPE;
    }

    private function attributeLoader(): ControllerAttributeLoader
    {
        return new ControllerAttributeLoader($this->env);
    }
}
