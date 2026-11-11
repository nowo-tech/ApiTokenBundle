<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Unit\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\ApiTokenBundle\Contract\LookupId;
use Nowo\ApiTokenBundle\Contract\OwnerRef;
use Nowo\ApiTokenBundle\Contract\TokenId;
use Nowo\ApiTokenBundle\Domain\ApiToken;
use Nowo\ApiTokenBundle\Domain\LookupIdTakenException;
use Nowo\ApiTokenBundle\Infrastructure\Persistence\AfterCommitQueue;
use Nowo\ApiTokenBundle\Infrastructure\Persistence\DbalApiTokenStore;
use Nowo\ApiTokenBundle\Tests\Fixtures\MemoryLogger;
use PHPUnit\Framework\TestCase;
use stdClass;

use function str_repeat;

/**
 * {@see DbalApiTokenStore} on a bare DBAL connection (no entity manager of that name): damaged rows, the DBAL fallback,
 * the reconnect-once rule and the guard on writes inside a host transaction.
 */
final class DbalApiTokenStoreTest extends TestCase
{
    private const ID = '01933b1c-5d2e-7a3b-8c4d-123456789abc';

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('CREATE TABLE nowo_api_token (id VARCHAR(36) PRIMARY KEY, lookup_id VARCHAR(12) NOT NULL UNIQUE, secret_hash VARCHAR(64), hash_version VARCHAR(8), owner_type VARCHAR(32), owner_id VARCHAR(128), name VARCHAR(80), grants CLOB, reach_everything BOOLEAN, reach CLOB, created_at VARCHAR(40), expires_at VARCHAR(40), last_used_at VARCHAR(40), created_via VARCHAR(16), legacy_hash VARCHAR(64))');
    }

    public function testFallsBackToTheDbalConnectionOfThatName(): void
    {
        $store = $this->store($this->registry($this->connection));
        $store->insert($this->token());

        self::assertSame(self::ID, $store->findById(new TokenId(self::ID))?->id()->value);
        self::assertSame(42, $store->transactional(static fn (): int => 42));
    }

    public function testAnInsertOfAnExistingLookupIdIsReported(): void
    {
        $store = $this->store($this->registry($this->connection));
        $store->insert($this->token());

        $this->expectException(LookupIdTakenException::class);
        $store->insert($this->token());
    }

    public function testLastUsedIsNeverWrittenInsideAHostTransaction(): void
    {
        $store = $this->store($this->registry($this->connection));
        $store->insert($this->token());
        $now = new DateTimeImmutable('2026-11-12 10:00:00', new DateTimeZone('UTC'));

        $this->connection->beginTransaction();
        self::assertFalse($store->touchLastUsed(new TokenId(self::ID), $now, $now));
        $this->connection->rollBack();
        self::assertTrue($store->touchLastUsed(new TokenId(self::ID), $now, $now));
    }

    public function testDamagedRowsAreSkippedAndLogged(): void
    {
        $logger = new MemoryLogger();
        $store  = $this->store($this->registry($this->connection), $logger);
        $rows   = [
            // ISO date (not the platform format) still reads; grants not JSON, not an array, or NULL read as nothing.
            ['aaaaaaaaaaaa', '1', '2026-11-12T10:00:00+00:00', 'not json', 'user'],
            ['bbbbbbbbbbbb', '1', '2026-11-12 10:00:00', '5', 'user'],
            ['cccccccccccc', '1', '2026-11-12 10:00:00', null, 'user'],
            // Damaged: unreadable date, non-numeric hash version, missing owner type.
            ['dddddddddddd', '1', 'not a date', '{}', 'user'],
            ['eeeeeeeeeeee', 'x', '2026-11-12 10:00:00', '{}', 'user'],
            ['ffffffffffff', '1', '2026-11-12 10:00:00', '{}', null],
        ];
        foreach ($rows as $i => [$lookup, $version, $created, $grants, $ownerType]) {
            $this->connection->insert('nowo_api_token', [
                'id'               => '01933b1c-5d2e-7a3b-8c4d-12345678900' . $i,
                'lookup_id'        => $lookup,
                'secret_hash'      => str_repeat('0', 64),
                'hash_version'     => $version,
                'owner_type'       => $ownerType,
                'owner_id'         => '1',
                'name'             => 'Row ' . $i,
                'grants'           => $grants,
                'reach_everything' => 1,
                'reach'            => '[]',
                'created_at'       => $created,
                'expires_at'       => '2027-01-01 00:00:00',
                'last_used_at'     => null,
                'created_via'      => 'account',
            ]);
        }

        $tokens = $store->ofOwner(new OwnerRef('user', '1'), 10);
        self::assertCount(3, $tokens);
        foreach ($tokens as $token) {
            self::assertSame([], $token->grants()->levels);
        }
        self::assertSame('2026-11-12 10:00:00', $store->findByLookupId(new LookupId('aaaaaaaaaaaa'))?->createdAt()->format('Y-m-d H:i:s'));
        self::assertNull($store->findByLookupId(new LookupId('dddddddddddd')));
        self::assertNull($store->findByLookupId(new LookupId('ffffffffffff')));
        self::assertCount(4, $logger->records);
    }

    public function testALostConnectionIsRetriedOnceOutsideATransaction(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
        $connection->expects(self::once())->method('close');
        $calls = 0;
        $connection->method('fetchAllAssociative')->willReturnCallback(static function () use (&$calls): array {
            if (++$calls === 1) {
                throw self::lost();
            }

            return [];
        });
        $logger = new MemoryLogger();

        self::assertNull($this->store($this->registry($connection), $logger)->findById(new TokenId(self::ID)));
        self::assertSame(2, $calls);
        self::assertCount(1, $logger->records);
    }

    public function testALostConnectionInsideATransactionIsNotRetried(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
        $connection->expects(self::never())->method('close');
        $connection->method('fetchAllAssociative')->willThrowException(self::lost());

        $this->expectException(ConnectionLost::class);
        $this->store($this->registry($connection))->findById(new TokenId(self::ID));
    }

    public function testANameThatIsNeitherAManagerNorAConnectionIsRefused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('names neither an ORM entity manager nor a DBAL connection');
        $this->store($this->registry(new stdClass()))->findById(new TokenId(self::ID));
    }

    private function store(ManagerRegistry $registry, ?MemoryLogger $logger = null): DbalApiTokenStore
    {
        return new DbalApiTokenStore($registry, new AfterCommitQueue(), 'default', 'nowo_', $logger);
    }

    private function registry(object $connection): ManagerRegistry
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerNames')->willReturn([]);
        $registry->method('getConnection')->willReturn($connection);

        return $registry;
    }

    private function token(): ApiToken
    {
        $utc = new DateTimeZone('UTC');

        return new ApiToken(self::ID, 'k7m2q7x4ab3c', str_repeat('a', 64), ApiToken::HASH_SHA256, 'user', '1', 'CI', ['levels' => []], true, [], new DateTimeImmutable('2026-11-12 10:00:00', $utc), new DateTimeImmutable('2027-01-01 00:00:00', $utc));
    }

    private static function lost(): ConnectionLost
    {
        return new ConnectionLost(new class('server has gone away') extends AbstractException {
        }, null);
    }
}
