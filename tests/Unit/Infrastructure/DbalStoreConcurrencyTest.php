<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Unit\Infrastructure;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\ApiTokenBundle\Contract\Exception\ApiTokenConflictException;
use Nowo\ApiTokenBundle\Contract\OwnerRef;
use Nowo\ApiTokenBundle\Infrastructure\Persistence\AfterCommitQueue;
use Nowo\ApiTokenBundle\Infrastructure\Persistence\DbalApiTokenStore;
use Nowo\ApiTokenBundle\Tests\Fixtures\MemoryLogger;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\TransientFailureKind;
use PHPUnit\Framework\TestCase;

use function str_contains;

/**
 * {@see DbalApiTokenStore} under contention (REQ-RUNTIME-003): the owner lock row, the replay of lock conflicts, the
 * conflict codes and PostgreSQL's lock_timeout. The real two-connection races are in the Database suite.
 */
final class DbalStoreConcurrencyTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('CREATE TABLE nowo_api_token_owner_lock (owner_type VARCHAR(32) NOT NULL, owner_id VARCHAR(128) NOT NULL, version INTEGER NOT NULL, PRIMARY KEY (owner_type, owner_id))');
    }

    public function testTheOwnerLockNeedsATransaction(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('lockOwner() must run inside transactional()');
        $this->store($this->connection)->lockOwner(new OwnerRef('user', '1'));
    }

    public function testTheOwnerLockRowIsCreatedOnceThenUpdated(): void
    {
        $store = $this->store($this->connection);
        $owner = new OwnerRef('user', 'ORG-1');
        $store->transactional(static fn () => $store->lockOwner($owner));
        $store->transactional(static fn () => $store->lockOwner($owner));
        $store->transactional(static fn () => $store->lockOwner(new OwnerRef('user', 'org-1')));

        self::assertSame([['owner_id' => 'ORG-1', 'version' => 2], ['owner_id' => 'org-1', 'version' => 1]], $this->connection->fetchAllAssociative('SELECT owner_id, version FROM nowo_api_token_owner_lock ORDER BY owner_id'));
    }

    public function testAConcurrentlyCreatedLockRowIsUpdatedInstead(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->expects(self::exactly(2))->method('executeStatement')->willReturnOnConsecutiveCalls(0, 1);
        $connection->method('transactional')->willThrowException(new UniqueConstraintViolationException(new class('duplicate') extends AbstractException {
        }, null));

        $this->store($connection)->lockOwner(new OwnerRef('user', '1'));
    }

    public function testALockRowThatCannotBeTakenIsReported(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->method('executeStatement')->willReturn(0);
        $connection->method('transactional')->willThrowException(new UniqueConstraintViolationException(new class('duplicate') extends AbstractException {
        }, null));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('could not be taken');
        $this->store($connection)->lockOwner(new OwnerRef('user', '1'));
    }

    public function testALockConflictIsReplayedThenSucceeds(): void
    {
        $logger = new MemoryLogger();
        $calls  = 0;
        $result = $this->store($this->connection, $logger)->transactional(static function () use (&$calls): string {
            if (++$calls < 3) {
                throw self::deadlock();
            }

            return 'ok';
        });

        self::assertSame('ok', $result);
        self::assertSame(3, $calls);
        $replays = 0;
        foreach ($logger->records as $record) {
            $replays += str_contains($record['message'], 'replaying the unit') ? 1 : 0;
        }
        self::assertSame(2, $replays);
        self::assertFalse($this->connection->isTransactionActive());
    }

    public function testAPersistentLockConflictBecomesConflict001(): void
    {
        $calls    = 0;
        $conflict = $this->conflictOf(function () use (&$calls): void {
            $this->store($this->connection)->transactional(static function () use (&$calls): never {
                ++$calls;

                throw self::deadlock();
            });
        });

        self::assertSame(ConflictCode::RetrySafe, $conflict->conflictCode);
        self::assertSame(3, $conflict->conflict()->attempts);
        self::assertSame(TransientFailureKind::Deadlock, $conflict->conflict()->kind);
        self::assertInstanceOf(DeadlockException::class, $conflict->conflict()->getPrevious());
        self::assertSame(1, $conflict->retryAfterSeconds());
        self::assertSame(3, $calls);
    }

    public function testInsideAHostTransactionNothingIsReplayed(): void
    {
        $calls = 0;
        $this->connection->beginTransaction();
        $conflict = $this->conflictOf(function () use (&$calls): void {
            $this->store($this->connection)->transactional(static function () use (&$calls): never {
                ++$calls;

                throw self::deadlock();
            });
        });

        self::assertSame(1, $conflict->conflict()->attempts);
        self::assertSame(1, $calls);
        self::assertSame(1, $this->connection->getTransactionNestingLevel(), 'the host transaction is left at its level');
        $this->connection->rollBack();
    }

    public function testALostSavepointBecomesConflict002(): void
    {
        $conflict = $this->conflictOf(fn (): mixed => $this->store($this->connection)->transactional(static function (): never {
            throw new class('SAVEPOINT DOCTRINE_2 does not exist', '42000', 1305) extends AbstractException {
            };
        }));

        self::assertSame(ConflictCode::Committed, $conflict->conflictCode);
        self::assertNull($conflict->retryAfterSeconds());
    }

    public function testConflictsAndOtherFailuresPassThrough(): void
    {
        $own = ApiTokenConflictException::retrySafe(self::deadlock(), TransientFailureKind::Deadlock, 1, 1);
        try {
            $this->store($this->connection)->transactional(static fn (): never => throw $own);
        } catch (ApiTokenConflictException $conflict) {
            self::assertSame($own, $conflict);
        }

        try {
            $this->store($this->connection, null, 1)->transactional(static fn (): never => throw new TransientStorageFailure());
        } catch (ApiTokenConflictException $conflict) {
            self::assertSame(TransientFailureKind::Retryable, $conflict->conflict()->kind);
        }

        $this->expectException(LogicException::class);
        $this->store($this->connection)->transactional(static fn (): never => throw new LogicException('domain'));
    }

    public function testPostgreSqlTransactionsTheStoreOpensGetALockTimeout(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $connection->expects(self::once())->method('executeStatement')->with("SET LOCAL lock_timeout = '1500ms'");

        self::assertSame(7, $this->store($connection, null, 3, 1500)->transactional(static fn (): int => 7));
    }

    /**
     * @param Closure(): mixed $call
     */
    private function conflictOf(Closure $call): ApiTokenConflictException
    {
        try {
            $call();
        } catch (ApiTokenConflictException $conflict) {
            return $conflict;
        }

        self::fail('no conflict');
    }

    private function store(Connection $connection, ?MemoryLogger $logger = null, int $attempts = 3, int $lockTimeoutMs = 5000): DbalApiTokenStore
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerNames')->willReturn([]);
        $registry->method('getConnection')->willReturn($connection);

        return new DbalApiTokenStore($registry, new AfterCommitQueue(), 'default', 'nowo_', $logger, $attempts, $lockTimeoutMs, 1);
    }

    private static function deadlock(): DeadlockException
    {
        return new DeadlockException(new class('Deadlock found', '40001', 1213) extends AbstractException {
        }, null);
    }
}
