<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Unit;

use Nowo\ApiTokenBundle\DependencyInjection\NowoApiTokenExtension;
use Nowo\ApiTokenBundle\NowoApiTokenBundle;
use PHPUnit\Framework\TestCase;

final class BundleTest extends TestCase
{
    public function testTheExtensionAliasIsTheConfigurationRootAndIsBuiltOnce(): void
    {
        $bundle    = new NowoApiTokenBundle();
        $extension = $bundle->getContainerExtension();

        self::assertInstanceOf(NowoApiTokenExtension::class, $extension);
        self::assertSame('nowo_api_token', $extension->getAlias());
        self::assertSame($extension, $bundle->getContainerExtension());
    }
}
