<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Contract;

use DateTimeImmutable;
use Nowo\ApiTokenBundle\Contract\Exception\InvalidApiTokenArgumentException;

use function max;

/**
 * The answer of one {@see ApiTokenRateLimiterInterface} call: whether the request may go on, the budget and, when it
 * was refused, when to retry. Carries no token, no IP and no owner.
 */
final readonly class RateLimitState
{
    /**
     * @param int $limit the budget of the window
     * @param int $remaining what is left of it after this call
     * @param DateTimeImmutable $retryAfter when a refused request may be retried (the call time when accepted)
     *
     * @throws InvalidApiTokenArgumentException when a count is negative or the remainder exceeds the limit
     */
    public function __construct(
        public RateLimitScope $scope,
        public bool $accepted,
        public int $limit,
        public int $remaining,
        public DateTimeImmutable $retryAfter,
    ) {
        if ($limit < 0 || $remaining < 0 || $remaining > $limit) {
            throw new InvalidApiTokenArgumentException('A rate limit state needs 0 <= remaining <= limit.');
        }
    }

    /**
     * Whole seconds until a retry, at least 1 for a refused state and 0 for an accepted one (the `Retry-After` value).
     */
    public function retryAfterSeconds(DateTimeImmutable $now): int
    {
        if ($this->accepted) {
            return 0;
        }

        return max(1, $this->retryAfter->getTimestamp() - $now->getTimestamp());
    }
}
