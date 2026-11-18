<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Integration\Database;

use Nowo\ApiTokenBundle\Tests\Integration\Kernel\Host;

/**
 * Race tests on the standard host.
 */
final class StandardHostConcurrencyTest extends ConcurrencyTestCase
{
    protected function host(): Host
    {
        return Host::standard();
    }
}
