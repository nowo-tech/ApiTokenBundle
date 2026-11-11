<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Fixtures\Standard;

use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The host's `security.user_checker`: a disabled user is refused.
 */
final class ActiveUserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if ($user instanceof User && !$user->active) {
            throw new DisabledException('Disabled.');
        }
    }

    public function checkPostAuth(UserInterface $user, mixed ...$rest): void
    {
    }
}
