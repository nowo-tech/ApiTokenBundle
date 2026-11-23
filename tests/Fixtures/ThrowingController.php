<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Fixtures;

use Error;
use Symfony\Component\HttpFoundation\Response;

/**
 * Host controller that dies with an `\Error` (audit ZAB-R-L1): with `framework.handle_all_throwables: false`,
 * HttpKernel rethrows it without dispatching `kernel.finish_request`.
 */
final class ThrowingController
{
    public function __invoke(): Response
    {
        throw new Error('host bug');
    }
}
