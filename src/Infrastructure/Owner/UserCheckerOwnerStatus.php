<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Infrastructure\Owner;

use Nowo\ApiTokenBundle\Contract\OwnerStatusInterface;
use Symfony\Component\Security\Core\Exception\AccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Default {@see OwnerStatusInterface}: runs the host's `security.user_checker` `checkPreAuth()` on every call; an
 * {@see AccountStatusException} (disabled, locked, expired, …) means inactive. Without SecurityBundle, or for a user
 * object that is not a {@see UserInterface}, the owner is active when the resolver found it.
 */
final readonly class UserCheckerOwnerStatus implements OwnerStatusInterface
{
    /**
     * @param UserCheckerInterface|null $userChecker the host's `security.user_checker`, if any
     */
    public function __construct(private ?UserCheckerInterface $userChecker = null)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function isActive(object $user): bool
    {
        if (!$this->userChecker instanceof UserCheckerInterface || !$user instanceof UserInterface) {
            return true;
        }
        try {
            $this->userChecker->checkPreAuth($user);
        } catch (AccountStatusException) {
            return false;
        }

        return true;
    }
}
