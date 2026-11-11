<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\ApiTokenBundle\DependencyInjection\NowoApiTokenExtension;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The enabled bundle stores its tokens through Doctrine DBAL: refuses to build without DoctrineBundle, with a message
 * that says so (instead of a missing `doctrine` service further down).
 */
final class RequireDoctrinePass implements CompilerPassInterface
{
    /**
     * Checks for the `doctrine` registry.
     */
    public function process(ContainerBuilder $container): void
    {
        if ($container->getParameter(NowoApiTokenExtension::ALIAS . '.enabled') === true && !$container->has('doctrine')) {
            throw new LogicException('nowo_api_token is enabled but DoctrineBundle is not: install and enable doctrine/doctrine-bundle (the tokens are stored through Doctrine DBAL).');
        }
    }
}
