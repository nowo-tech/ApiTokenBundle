<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Contract\Event;

use DateTimeImmutable;
use Nowo\ApiTokenBundle\Contract\Grants;
use Nowo\ApiTokenBundle\Contract\LookupId;
use Nowo\ApiTokenBundle\Contract\OwnerRef;
use Nowo\ApiTokenBundle\Contract\TokenId;

/**
 * A token was issued; dispatched after the insert commits. Never carries the secret or the hash.
 */
final readonly class ApiTokenIssuedEvent
{
    /**
     * @param int|string $reachSummary `everything` or the number of reach entries
     */
    public function __construct(
        public TokenId $tokenId,
        public LookupId $lookupId,
        public OwnerRef $owner,
        public string $name,
        public Grants $grants,
        public string|int $reachSummary,
        public DateTimeImmutable $expiresAt,
        public string $createdVia,
    ) {
    }
}
