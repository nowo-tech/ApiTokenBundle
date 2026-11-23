<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Fixtures;

use Nowo\ApiTokenBundle\Contract\OwnerRef;
use Nowo\ApiTokenBundle\Contract\ReachChoice;
use Nowo\ApiTokenBundle\Contract\ReachProviderInterface;

/**
 * Host reach choices of the page tests: two projects.
 */
final class PlacesReachProvider implements ReachProviderInterface
{
    public function choicesFor(OwnerRef $owner): array
    {
        return [new ReachChoice('p1', 'Project one', 'Projects'), new ReachChoice('p2', 'Project two', 'Projects'), new ReachChoice('general', 'General')];
    }
}
