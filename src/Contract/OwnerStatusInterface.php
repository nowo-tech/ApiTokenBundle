<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Contract;

/**
 * Host SPI: whether the person may use their tokens right now (checked on every request, never cached). The default
 * runs the host's `security.user_checker`.
 */
interface OwnerStatusInterface
{
    /**
     * False refuses the token ({@see AuthFailure::OwnerInactive}) without changing its row.
     */
    public function isActive(object $user): bool;
}
