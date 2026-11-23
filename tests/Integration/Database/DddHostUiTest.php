<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Integration\Database;

use Nowo\ApiTokenBundle\Tests\Integration\Kernel\Host;

/**
 * The S3 page suite on the DDD host (own owner resolver, own re-auth gate in reauth.gate).
 */
final class DddHostUiTest extends UiFunctionalTestCase
{
    protected function host(): Host
    {
        return Host::ddd();
    }

    protected function goodReauth(): string
    {
        return '000000';
    }

    protected function reauthField(string $form): string
    {
        return $form . '[reauth][code]';
    }
}
