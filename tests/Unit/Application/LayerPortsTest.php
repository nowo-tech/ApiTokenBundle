<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Unit\Application;

use DateTimeImmutable;
use Nowo\ApiTokenBundle\Application\Clock\SystemClock;
use Nowo\ApiTokenBundle\Application\Identity\RandomIdGenerator;
use Nowo\ApiTokenBundle\Infrastructure\Identity\UuidV7IdGenerator;
use PHPUnit\Framework\TestCase;

use function hexdec;
use function microtime;
use function str_replace;
use function substr;

/**
 * The REQ-ARCH-002 ports and moved constants: the symfony/uid adapter the container wires and the plain-PHP fallback
 * both mint canonical, distinct UUIDv7 values; the fallback clock reads the system time.
 */
final class LayerPortsTest extends TestCase
{
    private const UUID_V7 = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';

    public function testSymfonyAdapterMintsOrderedUuidV7(): void
    {
        $ids   = new UuidV7IdGenerator();
        $first = $ids->next();
        $next  = $ids->next();

        self::assertMatchesRegularExpression(self::UUID_V7, $first);
        self::assertLessThan($next, $first, 'monotonic within the process');
    }

    public function testFallbackMintsDistinctUuidV7WithTheCurrentTime(): void
    {
        $before = (int) (microtime(true) * 1000);
        $ids    = new RandomIdGenerator();
        $first  = $ids->next();
        $second = $ids->next();
        $after  = (int) (microtime(true) * 1000);

        self::assertMatchesRegularExpression(self::UUID_V7, $first);
        self::assertMatchesRegularExpression(self::UUID_V7, $second);
        self::assertNotSame($first, $second);

        $milliseconds = (int) hexdec(substr(str_replace('-', '', $first), 0, 12));
        self::assertGreaterThanOrEqual($before, $milliseconds);
        self::assertLessThanOrEqual($after, $milliseconds);
    }

    public function testFallbackClockReadsTheSystemTime(): void
    {
        $before = new DateTimeImmutable();
        $now    = (new SystemClock())->now();

        self::assertGreaterThanOrEqual($before, $now);
        self::assertLessThanOrEqual(new DateTimeImmutable(), $now);
    }
}
