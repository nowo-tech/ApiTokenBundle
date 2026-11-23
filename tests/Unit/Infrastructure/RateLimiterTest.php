<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Unit\Infrastructure;

use DateTimeImmutable;
use Nowo\ApiTokenBundle\Contract\RateLimitScope;
use Nowo\ApiTokenBundle\Contract\TokenId;
use Nowo\ApiTokenBundle\Infrastructure\RateLimit\SymfonyApiTokenRateLimiter;
use Nowo\ApiTokenBundle\Infrastructure\Security\ClientIpHasher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

use function serialize;
use function str_contains;

/**
 * The default limiter (contract §6.7, §11 "Rate limits"): one bucket per token, a write sub-budget, and the failed-IP
 * budget peeked without counting, all in the cache pool with the IP keyed by an HMAC.
 */
final class RateLimiterTest extends TestCase
{
    private ArrayAdapter $pool;

    protected function setUp(): void
    {
        $this->pool = new ArrayAdapter();
    }

    public function testOneBucketPerTokenAndAWriteSubBudget(): void
    {
        $limiter = $this->limiter();
        $a       = new TokenId('01935f7e-0000-7000-8000-00000000000a');
        $b       = new TokenId('01935f7e-0000-7000-8000-00000000000b');

        $first = $limiter->consume($a);
        self::assertSame(RateLimitScope::Token, $first->scope);
        self::assertTrue($first->accepted);
        self::assertSame(3, $first->limit);
        self::assertSame(2, $first->remaining);
        $limiter->consume($a);
        $limiter->consume($a);
        $refused = $limiter->consume($a);
        self::assertFalse($refused->accepted);
        self::assertSame(0, $refused->remaining);
        self::assertTrue($limiter->consume($b)->accepted, 'another token has its own bucket');

        $write = $limiter->consumeWrite($a);
        self::assertSame(RateLimitScope::Write, $write->scope);
        self::assertTrue($write->accepted, 'the write budget is separate');
        self::assertFalse($limiter->consumeWrite($a)->accepted);
    }

    public function testTheFailedIpBudgetIsPeekedWithoutCountingAndRefusedOnceUsedUp(): void
    {
        $limiter = $this->limiter();

        for ($i = 0; $i < 5; ++$i) {
            $peek = $limiter->failedTokenState('203.0.113.9');
            self::assertTrue($peek->accepted);
            self::assertSame(2, $peek->remaining, 'a peek counts nothing');
        }
        $limiter->registerFailedToken('203.0.113.9');
        self::assertSame(1, $limiter->failedTokenState('203.0.113.9')->remaining);
        $limiter->registerFailedToken('203.0.113.9');

        $blocked = $limiter->failedTokenState('203.0.113.9');
        self::assertSame(RateLimitScope::FailedIp, $blocked->scope);
        self::assertFalse($blocked->accepted);
        self::assertGreaterThanOrEqual(1, $blocked->retryAfterSeconds(new DateTimeImmutable()));
        self::assertFalse($limiter->failedTokenState('203.0.113.9')->accepted, 'reading a blocked IP again does not unblock it');
        self::assertTrue($limiter->failedTokenState('198.51.100.7')->accepted);
        self::assertTrue($limiter->failedTokenState('')->accepted, 'an unknown IP has its own bucket');
    }

    public function testTheCacheNeverHoldsARawIp(): void
    {
        $limiter = $this->limiter();
        $limiter->registerFailedToken('203.0.113.9');

        $dump = serialize($this->pool->getValues());

        self::assertFalse(str_contains($dump, '203.0.113.9'));
        self::assertTrue(str_contains($dump, (new ClientIpHasher('secret'))->full('203.0.113.9')));
    }

    private function limiter(): SymfonyApiTokenRateLimiter
    {
        $storage = new CacheStorage($this->pool);
        $factory = static fn (string $id, int $limit): RateLimiterFactory => new RateLimiterFactory(['id' => $id, 'policy' => 'fixed_window', 'limit' => $limit, 'interval' => '1 minute'], $storage);

        return new SymfonyApiTokenRateLimiter($factory('t', 3), $factory('w', 1), $factory('ip', 2), new ClientIpHasher('secret'));
    }
}
