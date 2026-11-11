<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Integration\Database;

use Nowo\ApiTokenBundle\Tests\Integration\Kernel\Host;

/**
 * Standard Symfony host: attribute `User` entity on the default entity manager, the bundle's Doctrine owner resolver,
 * the host's `security.user_checker`, the bundle's XML mapping prepended automatically, table prefix `nowo_`.
 */
final class StandardHostTest extends CoreFunctionalTestCase
{
    protected function host(): Host
    {
        return Host::standard();
    }
}
