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
     * must compare the id exactly (`===`).
     */
    public function userOf(OwnerRef $owner): ?object;
}
