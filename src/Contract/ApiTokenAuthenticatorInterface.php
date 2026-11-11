<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Contract;

use SensitiveParameter;

/**
 * Checks a presented token (contract §6.2): format and checksum, lookup, constant-time hash comparison, expiry, module
 * switch, owner status, grants narrowed.
 */
interface ApiTokenAuthenticatorInterface
{
    /**
     * Pure check, no side effect other than the throttled *last used* write. Every failure must be answered to the
     * client in the same way; the {@see AuthFailure} case is for events and logs only.
     */
    public function authenticate(#[SensitiveParameter] string $presented): ApiTokenPrincipal|AuthFailure;
}
