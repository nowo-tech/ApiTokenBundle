<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Presentation\Routing;

use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Routing\Loader\AttributeClassLoader;
use Symfony\Component\Routing\Route;

/**
 * Reads the `#[Route]` attributes of the bundle's controllers and sets `_controller` (no FrameworkBundle loader needed).
 */
final class ControllerAttributeLoader extends AttributeClassLoader
{
    /**
     * Sets `_controller` of a route read from a controller method.
     *
     * @param ReflectionClass<object> $class
     */
    protected function configureRoute(Route $route, ReflectionClass $class, ReflectionMethod $method, object $attr): void
    {
        $route->setDefaults(['_controller' => $class->getName() . '::' . $method->getName()] + $route->getDefaults());
    }
}
