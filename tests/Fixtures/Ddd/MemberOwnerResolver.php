<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Fixtures\Ddd;

use Doctrine\Persistence\ManagerRegistry;
use Nowo\ApiTokenBundle\Contract\Exception\InvalidApiTokenArgumentException;
use Nowo\ApiTokenBundle\Contract\OwnerRef;
use Nowo\ApiTokenBundle\Contract\OwnerResolverInterface;

/**
 * DDD host's own resolver: owner type `member`, the `ddd` entity manager, exact id re-check.
 */
final readonly class MemberOwnerResolver implements OwnerResolverInterface
{
    public function __construct(private ManagerRegistry $registry)
    {
    }

    public function ownerOf(object $user): OwnerRef
    {
        if (!$user instanceof Member) {
            throw new InvalidApiTokenArgumentException('Not a member.');
        }

        return new OwnerRef('member', $user->memberId());
    }

    public function userOf(OwnerRef $owner): ?object
    {
        if ($owner->type !== 'member') {
            return null;
        }
        $member = $this->registry->getManager('ddd')->find(Member::class, $owner->id);

        return $member instanceof Member && $member->memberId() === $owner->id ? $member : null;
    }
}
