<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Fixtures;

use Nowo\ApiTokenBundle\Contract\ApiTokenPolicyInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Points the policy port at {@see ShortPolicy} (the bundle has no config key for it: hosts alias or decorate it).
 */
final class ShortPolicyPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $container->setAlias(ApiTokenPolicyInterface::class, ShortPolicy::class)->setPublic(true);
    }
}
