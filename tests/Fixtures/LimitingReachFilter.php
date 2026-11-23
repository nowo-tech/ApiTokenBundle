<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Fixtures;

use Nowo\ApiTokenBundle\Contract\ApiTokenPrincipal;
use Nowo\ApiTokenBundle\Contract\Reach;
use Nowo\ApiTokenBundle\Contract\ReachFilterInterface;

/**
 * Host reach filter of the functional suite: a token named `narrow` only reaches `p1` (the way Beacon drops the
 * organisations that forbid tokens).
 */
final class LimitingReachFilter implements ReachFilterInterface
{
    public function filter(ApiTokenPrincipal $principal, Reach $reach): Reach
    {
        return $principal->tokenName === 'narrow' ? Reach::limitedTo(['p1']) : $reach;
    }
}
