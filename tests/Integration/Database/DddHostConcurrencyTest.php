<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Integration\Database;

use Nowo\ApiTokenBundle\Tests\Integration\Kernel\Host;

/**
 * Race tests on the ddd host.
 */
final class DddHostConcurrencyTest extends ConcurrencyTestCase
{
    protected function host(): Host
    {
        return Host::ddd();
    }
}
