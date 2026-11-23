<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Infrastructure\Security;

use Nowo\ApiTokenBundle\Contract\ApiTokenAccessCheckerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Default {@see ApiTokenAccessCheckerInterface} (`REQ-UI-002`): true when any role of `admin_ui.security.access_roles`
 * is granted; an empty list lets any signed-in user through (the host's firewall then decides alone).
 */
final readonly class ConfigurableApiTokenAccessChecker implements ApiTokenAccessCheckerInterface
{
    /**
     * @param list<string> $roles `admin_ui.security.access_roles`
     */
    public function __construct(private AuthorizationCheckerInterface $authorization, private array $roles)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function canAccess(object $user): bool
    {
        if ($this->roles === []) {
            return true;
        }
        foreach ($this->roles as $role) {
            if ($this->authorization->isGranted($role)) {
                return true;
            }
        }

        return false;
    }
}
