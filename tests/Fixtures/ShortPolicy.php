<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Fixtures;

use Nowo\ApiTokenBundle\Contract\ApiTokenPolicyInterface;
use Nowo\ApiTokenBundle\Contract\OwnerRef;

/**
 * A plan that allows 3-day tokens only (below every `expiry.choices` value) and the tasks module.
 */
final class ShortPolicy implements ApiTokenPolicyInterface
{
    public function mayIssue(OwnerRef $owner): bool
    {
        return true;
    }

    public function modulesFor(OwnerRef $owner): array
    {
        return ['tasks'];
    }

    public function maxTtlDays(OwnerRef $owner): int
    {
        return 3;
    }
}
