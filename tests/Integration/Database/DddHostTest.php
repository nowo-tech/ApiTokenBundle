<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Integration\Database;

use Nowo\ApiTokenBundle\Tests\Integration\Kernel\Host;

/**
 * DDD host: XML-mapped plain `Member` on a second entity manager `ddd`, `doctrine.auto_mapping: false` with the
 * bundle's XML registered by the host, its own owner resolver and status ports, owner type `member`, prefix `ddd_`.
 */
final class DddHostTest extends CoreFunctionalTestCase
{
    protected function host(): Host
    {
        return Host::ddd();
    }
}
