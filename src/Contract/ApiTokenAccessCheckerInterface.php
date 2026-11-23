<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Contract;

/**
 * Who may open the operator page (`REQ-UI-002`). Default: the roles of `admin_ui.security.access_roles` (any one
 * granted; an empty list = any signed-in user). A host sets `admin_ui.security.access_checker` to its own service.
 * Per-token decisions go through {@see Event\ApiTokenAccessCheckEvent}.
 */
interface ApiTokenAccessCheckerInterface
{
    /**
     * Whether the user may open the operator page.
     *
     * @param object $user the signed-in user
     */
    public function canAccess(object $user): bool;
}
