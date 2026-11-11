<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Fixtures\Ddd;

use Nowo\ApiTokenBundle\Contract\OwnerStatusInterface;

/**
 * DDD host's own status port.
 */
final readonly class MemberStatus implements OwnerStatusInterface
{
    public function isActive(object $user): bool
    {
        return $user instanceof Member && !$user->isSuspended();
    }
}
