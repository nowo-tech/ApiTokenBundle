<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Integration\Database;

use Nowo\ApiTokenBundle\Tests\Integration\Kernel\Host;

/**
 * The S2 firewall suite on the DDD host (XML-mapped Member on a second entity manager, own owner resolver).
 */
final class DddHostFirewallTest extends FirewallFunctionalTestCase
{
    protected function host(): Host
    {
        return Host::ddd();
    }
}
