<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Integration\Database;

use Nowo\ApiTokenBundle\Tests\Integration\Kernel\Host;

/**
 * The S2 firewall suite on the standard host (attribute User entity, Doctrine owner resolver, firewall user checker).
 */
final class StandardHostFirewallTest extends FirewallFunctionalTestCase
{
    protected function host(): Host
    {
        return Host::standard();
    }
}
