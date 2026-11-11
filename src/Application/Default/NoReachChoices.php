<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Application\Default;

use Nowo\ApiTokenBundle\Contract\OwnerRef;
use Nowo\ApiTokenBundle\Contract\ReachProviderInterface;

/**
 * Default {@see ReachProviderInterface}: no places to choose, so only *everything* can be issued (when
 * `reach.allow_everything` is true).
 */
final readonly class NoReachChoices implements ReachProviderInterface
{
    /**
     * No choices.
     */
    public function choicesFor(OwnerRef $owner): array
    {
        return [];
    }
}
