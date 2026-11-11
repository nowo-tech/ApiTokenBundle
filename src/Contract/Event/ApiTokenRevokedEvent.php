<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Contract\Event;

use Nowo\ApiTokenBundle\Contract\LookupId;
use Nowo\ApiTokenBundle\Contract\OwnerRef;
use Nowo\ApiTokenBundle\Contract\RevocationReason;
use Nowo\ApiTokenBundle\Contract\TokenId;

/**
 * A token was revoked (its row deleted); dispatched after the delete commits.
 */
final readonly class ApiTokenRevokedEvent
{
    /**
     * @param OwnerRef|null $actor who revoked it when not the owner (operator), else null
     */
    public function __construct(
        public TokenId $tokenId,
        public LookupId $lookupId,
        public OwnerRef $owner,
        public string $name,
        public RevocationReason $reason,
        public ?OwnerRef $actor,
    ) {
    }
}
