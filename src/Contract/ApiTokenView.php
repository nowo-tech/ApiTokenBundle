<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Contract;

use DateTimeImmutable;

/**
 * What lists, queries and events see of a token: never the secret, never the hash.
 */
final readonly class ApiTokenView
{
    /**
     * @param string|null $createdVia `account`, `cli`, … or null
     */
    public function __construct(
        public TokenId $id,
        public LookupId $lookupId,
        public OwnerRef $owner,
        public string $name,
        public Grants $grants,
        public Reach $reach,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $lastUsedAt,
        public ?string $createdVia,
    ) {
    }
}
