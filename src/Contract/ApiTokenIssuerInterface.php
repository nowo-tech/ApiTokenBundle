<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Contract;

use Nowo\ApiTokenBundle\Contract\Exception\ApiTokenIssueRefusedException;
use Nowo\ApiTokenBundle\Contract\Exception\InvalidApiTokenArgumentException;

/**
 * Issues personal tokens.
 */
interface ApiTokenIssuerInterface
{
    /**
     * Validates the grants against the registry, the reach against {@see ReachProviderInterface}, the expiry against
     * the policy; persists; dispatches {@see Event\ApiTokenIssuedEvent} after commit. The secret is in the result only.
     *
     * @throws InvalidApiTokenArgumentException when the request is malformed (name, grants shape, channel)
     * @throws ApiTokenIssueRefusedException when the request is well formed but not allowed
     */
    public function issue(IssueRequest $request): IssuedToken;
}
