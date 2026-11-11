<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Fixtures;

use Closure;
use DateTimeImmutable;
use Nowo\ApiTokenBundle\Contract\LookupId;
use Nowo\ApiTokenBundle\Contract\OwnerRef;
use Nowo\ApiTokenBundle\Contract\TokenId;
use Nowo\ApiTokenBundle\Domain\ApiToken;
use Nowo\ApiTokenBundle\Domain\ApiTokenStoreInterface;
use Nowo\ApiTokenBundle\Domain\LookupIdTakenException;
use Throwable;

use function array_slice;
use function array_values;
use function strcmp;
use function usort;

/**
 * Unit-test store: no database. `$takenLookups` makes the next inserts collide; `$lookups` counts lookups.
 */
final class InMemoryApiTokenStore implements ApiTokenStoreInterface
{
    /** @var array<string, ApiToken> */
    public array $tokens = [];

    public int $takenLookups = 0;

    public int $lookups = 0;

    public bool $touchResult = true;

    public ?Throwable $touchFailure = null;

    public function transactional(Closure $work, ?Closure $afterCommit = null): mixed
    {
        $backup = $this->tokens;
        try {
            $result = $work();
        } catch (Throwable $exception) {
            $this->tokens = $backup;

            throw $exception;
        }
        if ($afterCommit instanceof Closure) {
            $afterCommit($result);
        }

        return $result;
    }

    public function insert(ApiToken $token): void
    {
        if ($this->takenLookups > 0) {
            --$this->takenLookups;

            throw new LookupIdTakenException('taken');
        }
        $this->tokens[$token->id()->value] = $token;
    }

    public function findById(TokenId $id): ?ApiToken
    {
        return $this->tokens[$id->value] ?? null;
    }

    public function findByLookupId(LookupId $lookupId): ?ApiToken
    {
        ++$this->lookups;
        foreach ($this->tokens as $token) {
            if ($token->lookupId()->value === $lookupId->value) {
                return $token;
            }
        }

        return null;
    }

    public function ofOwner(OwnerRef $owner, int $limit): array
    {
        $found = [];
        foreach ($this->sorted() as $token) {
            if ($token->owner()->equals($owner)) {
                $found[] = $token;
            }
        }

        return array_slice($found, 0, $limit);
    }

    public function countLive(OwnerRef $owner, DateTimeImmutable $now): int
    {
        $count = 0;
        foreach ($this->tokens as $token) {
            if ($token->owner()->equals($owner) && !$token->isExpiredAt($now)) {
                ++$count;
            }
        }

        return $count;
    }

    public function delete(TokenId $id): bool
    {
        $exists = isset($this->tokens[$id->value]);
        unset($this->tokens[$id->value]);

        return $exists;
    }

    public function page(?TokenId $after, int $limit, ?OwnerRef $owner = null): array
    {
        $found = [];
        foreach ($this->sorted() as $token) {
            if ((!$after instanceof TokenId || strcmp($token->id()->value, $after->value) < 0) && (!$owner instanceof OwnerRef || $token->owner()->equals($owner))) {
                $found[] = $token;
            }
        }

        return array_slice($found, 0, $limit);
    }

    public function expiredBefore(DateTimeImmutable $cutoff, int $limit): array
    {
        $found = [];
        foreach ($this->tokens as $token) {
            if ($token->expiresAt() < $cutoff) {
                $found[] = $token;
            }
        }

        return array_slice($found, 0, $limit);
    }

    public function touchLastUsed(TokenId $id, DateTimeImmutable $now, DateTimeImmutable $olderThan): bool
    {
        if ($this->touchFailure instanceof Throwable) {
            throw $this->touchFailure;
        }

        return $this->touchResult;
    }

    /**
     * @return list<ApiToken>
     */
    private function sorted(): array
    {
        $tokens = array_values($this->tokens);
        usort($tokens, static fn (ApiToken $a, ApiToken $b): int => strcmp($b->id()->value, $a->id()->value));

        return $tokens;
    }
}
