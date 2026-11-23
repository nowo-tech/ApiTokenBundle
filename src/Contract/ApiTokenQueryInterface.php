<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Contract;

/**
 * Reads tokens as {@see ApiTokenView}s (never the hash).
 */
interface ApiTokenQueryInterface
{
    /**
     * The owner's tokens, newest first, capped by `max_per_owner`.
     *
     * @return list<ApiTokenView>
     */
    public function ofOwner(OwnerRef $owner): array;

    /**
     * One token by id, or null.
     */
    public function find(TokenId $id): ?ApiTokenView;

    /**
     * Operator page: keyset pagination, newest first; `$after` is the previous page's {@see ApiTokenPage::$nextAfter}.
     */
    public function page(?string $after, int $limit, ?OwnerRef $owner = null): ApiTokenPage;

    /**
     * The token with this lookup id (its public handle), for the operator page's leak search (S3). Exact match only.
     */
    public function findByLookupId(LookupId $lookupId): ?ApiTokenView;
}
