<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Unit\Contract;

use DateTimeImmutable;
use Nowo\ApiTokenBundle\Contract\Exception\InvalidApiTokenArgumentException;
use Nowo\ApiTokenBundle\Contract\RateLimitScope;
use Nowo\ApiTokenBundle\Contract\RateLimitState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RateLimitStateTest extends TestCase
{
    public function testRetryAfterIsZeroWhenAcceptedAndAtLeastOneSecondWhenRefused(): void
    {
        $now = new DateTimeImmutable('2026-11-23 10:00:00');

        self::assertSame(0, (new RateLimitState(RateLimitScope::Token, true, 10, 9, $now->modify('+30 seconds')))->retryAfterSeconds($now));
        self::assertSame(30, (new RateLimitState(RateLimitScope::Token, false, 10, 0, $now->modify('+30 seconds')))->retryAfterSeconds($now));
        self::assertSame(1, (new RateLimitState(RateLimitScope::FailedIp, false, 10, 0, $now->modify('-5 seconds')))->retryAfterSeconds($now));
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function invalid(): iterable
    {
        yield 'negative limit' => [-1, 0];
        yield 'negative remainder' => [5, -1];
        yield 'remainder above the limit' => [5, 6];
    }

    #[DataProvider('invalid')]
    public function testCountsAreValidated(int $limit, int $remaining): void
    {
        $this->expectException(InvalidApiTokenArgumentException::class);

        new RateLimitState(RateLimitScope::Write, true, $limit, $remaining, new DateTimeImmutable());
    }
}
