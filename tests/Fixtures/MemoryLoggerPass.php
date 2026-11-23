<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Fixtures;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Makes the test kernel's `logger` a public {@see MemoryLogger}, so a test can read every log record (audit ZAB-S3-M1:
 * a pasted secret must never reach a log).
 */
final class MemoryLoggerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $container->getDefinition('logger')->setClass(MemoryLogger::class)->setArguments([])->setPublic(true);
    }
}
