<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Integration\Database;

use Nowo\ApiTokenBundle\Tests\Integration\Kernel\Host;

/**
 * The S3 page suite on the standard host (password re-auth through the host password hasher).
 */
final class StandardHostUiTest extends UiFunctionalTestCase
{
    protected function host(): Host
    {
        return Host::standard();
    }

    protected function goodReauth(): string
    {
        return 'secret-pass';
    }

    protected function reauthField(string $form): string
    {
        return $form . '[reauth_password]';
    }
}
