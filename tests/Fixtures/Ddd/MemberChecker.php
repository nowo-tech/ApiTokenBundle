<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Fixtures\Ddd;

use Symfony\Component\Security\Core\Exception\LockedException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * DDD host's user checker, named in `owner.user_checker`: a suspended member is refused.
 */
final class MemberChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if ($user instanceof Member && $user->isSuspended()) {
            throw new LockedException('Suspended.');
        }
    }

    public function checkPostAuth(UserInterface $user, mixed ...$rest): void
    {
    }
}
