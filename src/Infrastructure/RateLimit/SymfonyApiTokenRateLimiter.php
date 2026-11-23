<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Infrastructure\RateLimit;

use Nowo\ApiTokenBundle\Contract\ApiTokenRateLimiterInterface;
use Nowo\ApiTokenBundle\Contract\RateLimitScope;
use Nowo\ApiTokenBundle\Contract\RateLimitState;
use Nowo\ApiTokenBundle\Contract\TokenId;
use Nowo\ApiTokenBundle\Infrastructure\Security\ClientIpHasher;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

use function min;

/**
 * Default {@see ApiTokenRateLimiterInterface} on `symfony/rate-limiter` fixed windows, stored in the host's cache pool
 * (`rate_limit.cache_pool`). The factories hold configuration only: every call creates its limiter from the key, so no
 * count, token or IP stays in process memory (worker mode). No lock is taken (contract §10): two exactly concurrent
 * calls may both pass the last unit of a window, which over-admits by at most the number of concurrent workers.
 */
final readonly class SymfonyApiTokenRateLimiter implements ApiTokenRateLimiterInterface
{
    /**
     * @param RateLimiterFactoryInterface $perToken `rate_limit.per_token`
     * @param RateLimiterFactoryInterface $writes `rate_limit.writes`
     * @param RateLimiterFactoryInterface $failedPerIp `rate_limit.failed_per_ip`
     */
    public function __construct(
        private RateLimiterFactoryInterface $perToken,
        private RateLimiterFactoryInterface $writes,
        private RateLimiterFactoryInterface $failedPerIp,
        private ClientIpHasher $ipHasher,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function consume(TokenId $tokenId): RateLimitState
    {
        return $this->state(RateLimitScope::Token, $this->perToken->create($tokenId->value)->consume());
    }

    /**
     * {@inheritDoc}
     */
    public function consumeWrite(TokenId $tokenId): RateLimitState
    {
        return $this->state(RateLimitScope::Write, $this->writes->create($tokenId->value)->consume());
    }

    /**
     * {@inheritDoc}
     *
     * A zero-unit consume is always "accepted" by symfony/rate-limiter, so an exhausted window is read through a
     * one-unit consume, which a full window refuses without counting it.
     */
    public function failedTokenState(string $clientIp): RateLimitState
    {
        $limiter = $this->failedPerIp->create($this->ipHasher->full($clientIp));
        $peek    = $limiter->consume(0);
        if ($peek->getRemainingTokens() > 0) {
            return $this->state(RateLimitScope::FailedIp, $peek);
        }

        return $this->state(RateLimitScope::FailedIp, $limiter->consume());
    }

    /**
     * {@inheritDoc}
     */
    public function registerFailedToken(string $clientIp): void
    {
        $this->failedPerIp->create($this->ipHasher->full($clientIp))->consume();
    }

    private function state(RateLimitScope $scope, RateLimit $limit): RateLimitState
    {
        $max = $limit->getLimit();

        return new RateLimitState($scope, $limit->isAccepted(), $max, min($max, $limit->getRemainingTokens()), $limit->getRetryAfter());
    }
}
