<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Infrastructure\Security;

use Nowo\ApiTokenBundle\Contract\ApiTokenAccessCheckerInterface;

/**
 * DEV / DEMO only (`admin_ui.security.allow_unauthenticated: true`, `REQ-UI-002`): everyone may open the operator page.
 */
final readonly class AllowAllApiTokenAccessChecker implements ApiTokenAccessCheckerInterface
{
    /**
     * Always true.
     */
    public function canAccess(object $user): bool
    {
        return true;
    }
}
