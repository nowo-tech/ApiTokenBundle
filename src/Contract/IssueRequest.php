<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Contract;

/**
 * What to issue: owner, name, grants, reach, lifetime in days (mandatory; at most the owner's maximum, ≤ 365) and the
 * channel that created it (`account`, `cli`, …).
 */
final readonly class IssueRequest
{
    /**
     * Holds the request; the issuer validates it.
     */
    public function __construct(
        public OwnerRef $owner,
        public string $name,
        public Grants $grants,
        public Reach $reach,
        public int $expiresInDays,
        public string $createdVia = 'account',
    ) {
    }
}
