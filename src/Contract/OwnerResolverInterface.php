<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Contract;

use Nowo\ApiTokenBundle\Contract\Exception\InvalidApiTokenArgumentException;

/**
 * Host SPI: maps the host's user object to an {@see OwnerRef} and back. The default resolver reads `owner.class`
 * through the host's ORM; DDD hosts implement it.
 */
interface OwnerResolverInterface
{
    /**
     * The reference of a user.
     *
     * @throws InvalidApiTokenArgumentException when the object is not a user this resolver knows
     */
    public function ownerOf(object $user): OwnerRef;

    /**
     * The user of a reference, or null when it does not exist (erased) or the type is not handled. Implementations
     * must compare the id exactly (`===`) and read the account's current state on every call: never answer from a
     * cache, a memo or an ORM identity map (worker mode with the kernel kept alive: a deactivation by another process
     * must take effect on the next request).
     */
    public function userOf(OwnerRef $owner): ?object;
}
