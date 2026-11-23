<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Fixtures;

use DateTimeImmutable;
use Nowo\ApiTokenBundle\Contract\ApiTokenRateLimiterInterface;
use Nowo\ApiTokenBundle\Contract\OwnerRef;
use Nowo\ApiTokenBundle\Contract\RateLimitScope;
use Nowo\ApiTokenBundle\Contract\RateLimitState;
use Nowo\ApiTokenBundle\Contract\TokenId;
use SensitiveParameter;

use function in_array;

/**
 * Scripted limiter of the firewall unit tests: records every call and refuses the scopes listed in `$refuse`.
 */
final class FakeRateLimiter implements ApiTokenRateLimiterInterface
{
    /** @var list<string> */
    public array $calls = [];

    /** @var list<RateLimitScope> */
    public array $refuse = [];

    public function __construct(private readonly DateTimeImmutable $now = new DateTimeImmutable('2026-11-12 10:00:00'))
    {
    }

    public function consume(TokenId $tokenId): RateLimitState
    {
        $this->calls[] = 'consume:' . $tokenId->value;

        return $this->state(RateLimitScope::Token);
    }

    public function consumeWrite(TokenId $tokenId): RateLimitState
    {
        $this->calls[] = 'write:' . $tokenId->value;

        return $this->state(RateLimitScope::Write);
    }

    public function failedTokenState(string $clientIp): RateLimitState
    {
        $this->calls[] = 'peek:' . $clientIp;

        return $this->state(RateLimitScope::FailedIp);
    }

    public function registerFailedToken(string $clientIp): void
    {
        $this->calls[] = 'failed:' . $clientIp;
    }

    public function consumeIssue(OwnerRef $owner): RateLimitState
    {
        $this->calls[] = 'issue:' . $owner->key();

        return $this->state(RateLimitScope::Issue);
    }

    public function consumeReauth(#[SensitiveParameter] string $sessionKey): RateLimitState
    {
        $this->calls[] = 'reauth';

        return $this->state(RateLimitScope::Reauth);
    }

    private function state(RateLimitScope $scope): RateLimitState
    {
        $refused = in_array($scope, $this->refuse, true);

        return new RateLimitState($scope, !$refused, 10, $refused ? 0 : 9, $refused ? $this->now->modify('+42 seconds') : $this->now);
    }
}
