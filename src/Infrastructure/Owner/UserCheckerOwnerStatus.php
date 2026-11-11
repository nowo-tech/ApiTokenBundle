<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Infrastructure\Owner;

use Nowo\ApiTokenBundle\Contract\OwnerStatusInterface;
use Nowo\ApiTokenBundle\DependencyInjection\Compiler\OwnerUserCheckerPass;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Default {@see OwnerStatusInterface}: runs the host's user checkers (`checkPreAuth()`) on every call. Which checkers
 * is decided at container build ({@see OwnerUserCheckerPass}): the
 * checker of every firewall (`firewalls.<name>.user_checker`, UserKit's account-status chain included), or the one
 * service named in `owner.user_checker`.
 *
 * Fails closed: an authentication or account-status exception from any checker means inactive, and so does a user
 * object that is not a Symfony {@see UserInterface} (no checker can judge it). Only an empty checker list
 * (`owner.user_checker: none`, an explicit host choice) leaves the decision to the owner resolver alone.
 */
final readonly class UserCheckerOwnerStatus implements OwnerStatusInterface
{
    /**
     * @param iterable<UserCheckerInterface> $userCheckers the host's checkers; empty only with `owner.user_checker: none`
     */
    public function __construct(private iterable $userCheckers = [])
    {
    }

    /**
     * {@inheritDoc}
     */
    public function isActive(object $user): bool
    {
        foreach ($this->userCheckers as $checker) {
            if (!$user instanceof UserInterface) {
                return false;
            }
            try {
                $checker->checkPreAuth($user);
            } catch (AuthenticationException) {
                return false;
            }
        }

        return true;
    }
}
