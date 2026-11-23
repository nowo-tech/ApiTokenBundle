<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Fixtures;

use RuntimeException;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;

/**
 * A host `kernel.finish_request` listener that runs before the bundle's reset and throws on requests that carry
 * `X-Throw-Finish` (audit ZAB-R-L1): the reset of that request never runs.
 */
final class ThrowingFinishRequestListener
{
    public function onFinishRequest(FinishRequestEvent $event): void
    {
        if ($event->getRequest()->headers->has('X-Throw-Finish')) {
            throw new RuntimeException('host finish_request listener failed');
        }
    }
}
