<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Unit\Infrastructure;

use DateTimeImmutable;
use Nowo\ApiTokenBundle\Application\Service\ApiTokenRevoker;
use Nowo\ApiTokenBundle\Infrastructure\Console\PurgeExpiredTokensCommand;
use Nowo\ApiTokenBundle\Tests\Fixtures\InMemoryApiTokenStore;
use Nowo\ApiTokenBundle\Tests\Fixtures\MemoryLogger;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * REQ-OBS-001: the destructive purge logs its start, its result and a failure.
 */
final class PurgeCommandTest extends TestCase
{
    public function testStartAndResultAreLogged(): void
    {
        $logger = new MemoryLogger();
        $tester = new CommandTester(new PurgeExpiredTokensCommand($this->revoker(new InMemoryApiTokenStore()), 30, 500, 50, new MockClock('2026-11-12 10:00:00'), $logger));

        self::assertSame(0, $tester->execute([]));
        self::assertCount(2, $logger->records);
        self::assertStringContainsString('started', $logger->records[0]['message']);
        self::assertSame('2026-10-13 10:00:00', $logger->records[0]['context']['cutoff'] ?? null);
        self::assertSame(0, $logger->records[1]['context']['deleted'] ?? null);
    }

    public function testAFailureIsLoggedAndRethrown(): void
    {
        $store               = new InMemoryApiTokenStore();
        $store->damaged['x'] = new DateTimeImmutable('2000-01-01');
        $store->failDeletes  = true;
        $logger              = new MemoryLogger();
        $tester              = new CommandTester(new PurgeExpiredTokensCommand($this->revoker($store), 30, 500, 50, null, $logger));

        try {
            $tester->execute([]);
            self::fail('the failure must reach the caller');
        } catch (RuntimeException) {
        }
        self::assertSame('error', $logger->records[1]['level'] ?? null);
        self::assertSame(RuntimeException::class, $logger->records[1]['context']['class'] ?? null);
    }

    private function revoker(InMemoryApiTokenStore $store): ApiTokenRevoker
    {
        return new ApiTokenRevoker($store, [], new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                return $event;
            }
        });
    }
}
