<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Application\Service;

use Nowo\ApiTokenBundle\Contract\ApiTokenPage;
use Nowo\ApiTokenBundle\Contract\ApiTokenQueryInterface;
use Nowo\ApiTokenBundle\Contract\ApiTokenView;
use Nowo\ApiTokenBundle\Contract\Exception\InvalidApiTokenArgumentException;
use Nowo\ApiTokenBundle\Contract\OwnerRef;
use Nowo\ApiTokenBundle\Contract\TokenId;
use Nowo\ApiTokenBundle\Domain\ApiToken;
use Nowo\ApiTokenBundle\Domain\ApiTokenStoreInterface;

use function array_map;
use function array_slice;
use function count;

/**
 * Default {@see ApiTokenQueryInterface}: views only (never the hash).
 */
final readonly class ApiTokenQuery implements ApiTokenQueryInterface
{
    /** Largest operator page. */
    public const MAX_PAGE = 200;

    /**
     * @param int $maxPerOwner `max_per_owner`: the cap of {@see self::ofOwner()}
     */
    public function __construct(private ApiTokenStoreInterface $store, private int $maxPerOwner = 20)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function ofOwner(OwnerRef $owner): array
    {
        return $this->views($this->store->ofOwner($owner, $this->maxPerOwner));
    }

    /**
     * {@inheritDoc}
     */
    public function find(TokenId $id): ?ApiTokenView
    {
        return $this->store->findById($id)?->toView();
    }

    /**
     * {@inheritDoc}
     *
     * @throws InvalidApiTokenArgumentException when the limit is outside 1 … 200 or the cursor is not a token id
     */
    public function page(?string $after, int $limit, ?OwnerRef $owner = null): ApiTokenPage
    {
        if ($limit < 1 || $limit > self::MAX_PAGE) {
            throw new InvalidApiTokenArgumentException('The page size must be between 1 and 200.');
        }
        $tokens = $this->store->page($after === null ? null : new TokenId($after), $limit + 1, $owner);
        $more   = count($tokens) > $limit;
        $tokens = array_slice($tokens, 0, $limit);

        return new ApiTokenPage($this->views($tokens), $more ? $tokens[$limit - 1]->id()->value : null);
    }

    /**
     * @param list<ApiToken> $tokens
     *
     * @return list<ApiTokenView>
     */
    private function views(array $tokens): array
    {
        return array_map(static fn (ApiToken $token): ApiTokenView => $token->toView(), $tokens);
    }
}
