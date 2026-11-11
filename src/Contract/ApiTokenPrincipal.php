<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Contract;

use DateTimeImmutable;

/**
 * The result of a successful authentication, built per request (never stored on a service): the token, its owner,
 * the host's user object, and the grants and reach already narrowed by the registry and the module switches.
 */
final readonly class ApiTokenPrincipal
{
    /**
     * @param object $user the host's user, resolved by {@see OwnerResolverInterface::userOf()}
     */
    public function __construct(
        public TokenId $tokenId,
        public string $tokenName,
        public OwnerRef $owner,
        public object $user,
        public Grants $grants,
        public Reach $reach,
        public DateTimeImmutable $expiresAt,
    ) {
    }
}
