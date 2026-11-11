<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Domain;

use Closure;
use DateTimeImmutable;
use Nowo\ApiTokenBundle\Contract\LookupId;
use Nowo\ApiTokenBundle\Contract\OwnerRef;
use Nowo\ApiTokenBundle\Contract\TokenId;
use Nowo\ApiTokenBundle\Infrastructure\Persistence\DbalApiTokenStore;

/**
 * Persistence port of the tokens (replaceable; default {@see DbalApiTokenStore}).
 *
 * Implementations never flush a host EntityManager and never keep a token managed. Every method that returns a
 * person's tokens re-checks the owner in PHP (`===`, audit LQ). Secret hashes are never used in a query condition.
 */
interface ApiTokenStoreInterface
{
    /**
     * Runs `$work` in a transaction. When the connection already has a transaction open (the host's), the work joins
     * it and `$afterCommit` waits for the outermost commit, or is dropped on rollback; otherwise `$afterCommit` runs
     * right after this commit. `$afterCommit` receives the work's result; its failures are logged, never thrown.
     *
     * @template T
     *
     * @param Closure(): T $work
     * @param (Closure(T): void)|null $afterCommit
     *
     * @return T
     */
    public function transactional(Closure $work, ?Closure $afterCommit = null): mixed;

    /**
     * Inserts a new token.
     *
     * @throws LookupIdTakenException when the lookup id exists
     */
    public function insert(ApiToken $token): void;

    /**
     * One token by id, or null.
     */
    public function findById(TokenId $id): ?ApiToken;

    /**
     * One token by lookup id (unique, binary), or null.
     */
    public function findByLookupId(LookupId $lookupId): ?ApiToken;

    /**
     * The owner's tokens, newest first.
     *
     * @return list<ApiToken>
     */
    public function ofOwner(OwnerRef $owner, int $limit): array;

    /**
     * How many of the owner's tokens are not expired at `$now`.
     */
    public function countLive(OwnerRef $owner, DateTimeImmutable $now): int;

    /**
     * Deletes one token; false when there was no such row.
     */
    public function delete(TokenId $id): bool;

    /**
     * Keyset page, newest first (`id` descending: UUIDv7 sorts by creation), after the given id.
     *
     * @return list<ApiToken>
     */
    public function page(?TokenId $after, int $limit, ?OwnerRef $owner = null): array;

    /**
     * Keyset batch of the rows that expired before `$cutoff`, by raw `id` ascending after `$afterId`. A damaged row
     * (it cannot be read as a token) comes with `token: null`, so the purge still removes it.
     *
     * @return list<array{id: string, token: ApiToken|null}>
     */
    public function expiredBatch(DateTimeImmutable $cutoff, int $limit, ?string $afterId = null): array;

    /**
     * Deletes a row by its raw stored id (the purge of damaged rows); false when there was no such row.
     */
    public function deleteRow(string $id): bool;

    /**
     * Writes *last used* = `$now` when it is null or older than `$olderThan`, outside any open transaction (returns
     * false, writing nothing, while one is open). True when the row was updated.
     */
    public function touchLastUsed(TokenId $id, DateTimeImmutable $now, DateTimeImmutable $olderThan): bool;
}
