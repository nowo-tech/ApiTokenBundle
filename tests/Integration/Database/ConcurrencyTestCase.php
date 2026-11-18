<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Integration\Database;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Types;
use Nowo\ApiTokenBundle\Application\Service\ApiTokenRevoker;
use Nowo\ApiTokenBundle\Contract\ApiTokenIssuerInterface;
use Nowo\ApiTokenBundle\Contract\Exception\ApiTokenConflictException;
use Nowo\ApiTokenBundle\Contract\Exception\ApiTokenIssueRefusedException;
use Nowo\ApiTokenBundle\Contract\Grants;
use Nowo\ApiTokenBundle\Contract\IssuedToken;
use Nowo\ApiTokenBundle\Contract\IssueRequest;
use Nowo\ApiTokenBundle\Contract\Reach;
use Nowo\ApiTokenBundle\Infrastructure\Http\ApiTokenConflictListener;
use Nowo\ApiTokenBundle\Tests\Integration\Kernel\Host;
use Nowo\ApiTokenBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\ApiTokenBundle\Tests\Integration\Kernel\TestKernel;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

use function base64_encode;
use function dirname;
use function fclose;
use function fgets;
use function fwrite;
use function hrtime;
use function is_resource;
use function json_encode;
use function proc_close;
use function proc_open;
use function str_repeat;
use function trim;

use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;
use const STDERR;

/**
 * Two-connection race tests (REQ-RUNTIME-003): a second PHP process (`tests/Fixtures/hold-lock.php`)
 * holds real row locks on the same database (SQLite, MySQL 8.4, MariaDB 11.4, PostgreSQL 16 in the Databases job)
 * while this process runs the bundle's services:
 *
 * - the live-token cap holds under a concurrent issue (the issuer waits for the owner lock row, then re-counts);
 * - a lock wait timeout on issue answers `CONFLICT_001` (409 + Retry-After + X-Conflict-Code) after the store's
 *   replays, writes nothing, and leaves the connection usable;
 * - a lock wait timeout in a later purge batch answers `CONFLICT_002` (no Retry-After): the first batch stays deleted.
 *
 * Every session has a bounded lock wait, so a regression fails fast instead of hanging the machine.
 */
abstract class ConcurrencyTestCase extends KernelTestCase
{
    private TestKernel $kernel;

    /** @var array{0: resource, 1: resource}|null child stdin / stdout */
    private ?array $pipes = null;

    /** @var resource|null */
    private $process;

    abstract protected function host(): Host;

    protected function tearDown(): void
    {
        $this->releaseChild();
        parent::tearDown();
    }

    public function testTheCapHoldsWhileAConcurrentIssueHoldsTheOwnerLock(): void
    {
        $this->boot(['max_per_owner' => 2], 10);
        $this->issue('first');

        // Session B: a concurrent issuer that took alice's lock row and inserted her second live token, not committed.
        $this->startChild([$this->lockRowWrite(), $this->tokenRowWrite('second', '01933b1c-5d2e-7a3b-8c4d-00000000b002', 'bbbbbbbbbbb2', 30)], 1000);
        $started = hrtime(true);
        try {
            $this->issue('third');
            self::fail('the third token passed the cap');
        } catch (ApiTokenIssueRefusedException $refused) {
            self::assertSame(ApiTokenIssueRefusedException::TOO_MANY, $refused->reason);
        }
        self::assertGreaterThan(500_000_000, hrtime(true) - $started, 'the issue waited for the concurrent lock holder');
        self::assertSame(2, $this->liveRows(), 'exactly max_per_owner live tokens');
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT 1'), 'the connection is still usable');
    }

    public function testALockWaitTimeoutOnIssueAnswersConflict001(): void
    {
        $this->boot(['concurrency' => ['attempts' => 2, 'lock_timeout_ms' => 1000]], 1);
        $this->issue('first');

        $this->startChild([$this->lockRowWrite()], null);
        try {
            $this->issue('second');
            self::fail('no conflict');
        } catch (ApiTokenConflictException $conflict) {
            self::assertSame(ConflictCode::RetrySafe, $conflict->conflictCode);
            self::assertSame(2, $conflict->conflict()->attempts, 'replayed once before giving up');
            self::assertSame(1, $conflict->retryAfterSeconds());
            $answer = $this->answer($conflict);
            self::assertSame(409, $answer->getStatusCode());
            self::assertSame(['X-Conflict-Code' => 'CONFLICT_001', 'Retry-After' => '1'], $answer->getHeaders());
        }
        self::assertSame(1, $this->liveRows(), 'nothing of the failed issue was written');
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT 1'), 'the connection is still usable');
        self::assertFalse($this->connection()->isTransactionActive());

        $this->releaseChild();
        self::assertSame('second', $this->issue('second')->token->name, 'a retry after the lock is released succeeds');
    }

    public function testALockWaitTimeoutInALaterPurgeBatchAnswersConflict002(): void
    {
        $this->boot(['concurrency' => ['attempts' => 1, 'lock_timeout_ms' => 1000]], 1);
        $first  = $this->issue('first', 1);
        $second = $this->issue('second', 1);
        $clock  = $this->service('clock');
        self::assertInstanceOf(MockClock::class, $clock);
        $clock->modify('+40 days');
        $revoker = $this->service(ApiTokenRevoker::class);
        self::assertInstanceOf(ApiTokenRevoker::class, $revoker);
        $cutoff = $clock->now()->modify('-30 days');
        // expiredBatch() goes by id ascending: the first batch deletes the older token, the second meets the lock.
        $locked = $second->token->id->value > $first->token->id->value ? $second : $first;

        try {
            $revoker->purgeExpired($cutoff, 1, function () use ($locked): bool {
                $this->startChild([[$this->table(), ['last_used_at' => $this->time(new DateTimeImmutable(Host::NOW, new DateTimeZone('UTC')))], [], ['id' => $locked->token->id->value]]], null);

                return true;
            });
            self::fail('no conflict');
        } catch (ApiTokenConflictException $conflict) {
            self::assertSame(ConflictCode::Committed, $conflict->conflictCode);
            self::assertSame(ConflictCode::Committed, ConflictCode::forFailure($conflict));
            self::assertNull($conflict->retryAfterSeconds());
            $answer = $this->answer($conflict);
            self::assertSame(409, $answer->getStatusCode());
            self::assertSame(['X-Conflict-Code' => 'CONFLICT_002'], $answer->getHeaders(), 'no Retry-After: read again, do not repeat');
        }
        $ids = $this->connection()->fetchFirstColumn('SELECT id FROM ' . $this->table());
        self::assertSame([$locked->token->id->value], $ids, 'the first batch stays deleted, the locked row stays');
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT 1'), 'the connection is still usable');
    }

    /**
     * @param array<string, mixed> $config
     */
    private function boot(array $config, int $lockWaitSeconds): void
    {
        $host         = $this->host();
        $this->kernel = $this->bootKernel($host->bundleConfig($config), Host::databaseUrl(), $host->configure());
        $host->resetSchema($this->kernel);
        $host->createUser($this->kernel, 'alice');
        $platform = $this->connection()->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $this->connection()->executeStatement('SET SESSION innodb_lock_wait_timeout = ' . $lockWaitSeconds);
        } elseif ($platform instanceof PostgreSQLPlatform) {
            $this->connection()->executeStatement("SET lock_timeout = '10s'");
        } elseif ($platform instanceof SQLitePlatform) {
            $this->connection()->executeStatement('PRAGMA busy_timeout = ' . ($lockWaitSeconds * 1000));
        }
    }

    private function issue(string $name, int $days = 30): IssuedToken
    {
        $issuer = $this->service(ApiTokenIssuerInterface::class);
        self::assertInstanceOf(ApiTokenIssuerInterface::class, $issuer);

        return $issuer->issue(new IssueRequest($this->host()->owner('alice'), $name, new Grants(['tasks' => 'read']), Reach::everything(), $days));
    }

    private function answer(Throwable $failure): HttpExceptionInterface
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $event  = new ExceptionEvent($kernel, Request::create('/api/tokens', 'POST'), HttpKernelInterface::MAIN_REQUEST, $failure);
        (new ApiTokenConflictListener(new ConflictResponsePolicy()))->onKernelException($event);
        $answer = $event->getThrowable();
        self::assertInstanceOf(HttpExceptionInterface::class, $answer);

        return $answer;
    }

    /**
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, string>, 3: array<string, mixed>}
     */
    private function lockRowWrite(): array
    {
        $owner = $this->host()->owner('alice');

        return [$this->host()->tablePrefix() . 'api_token_owner_lock', ['version' => 99], [], ['owner_type' => $owner->type, 'owner_id' => $owner->id]];
    }

    /**
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, string>, 3: null}
     */
    private function tokenRowWrite(string $name, string $id, string $lookupId, int $days): array
    {
        $owner = $this->host()->owner('alice');
        $now   = new DateTimeImmutable(Host::NOW, new DateTimeZone('UTC'));

        return [$this->table(), [
            'id'               => $id,
            'lookup_id'        => $lookupId,
            'secret_hash'      => str_repeat('0', 64),
            'hash_version'     => 1,
            'owner_type'       => $owner->type,
            'owner_id'         => $owner->id,
            'name'             => $name,
            'name_key'         => $name,
            'grants'           => '{"levels":{"tasks":"read"},"flags":{}}',
            'reach_everything' => true,
            'reach'            => '[]',
            'created_at'       => $this->time($now),
            'expires_at'       => $this->time($now->modify('+' . $days . ' days')),
            'last_used_at'     => null,
            'created_via'      => 'account',
            'legacy_hash'      => null,
        ], ['reach_everything' => Types::BOOLEAN, 'hash_version' => Types::INTEGER], null];
    }

    /**
     * Starts the second session and waits until it holds its locks.
     *
     * @param list<array{0: string, 1: array<string, mixed>, 2: array<string, string>, 3: array<string, mixed>|null}> $writes
     */
    private function startChild(array $writes, ?int $holdMs): void
    {
        $job = base64_encode(json_encode(['params' => $this->connection()->getParams(), 'writes' => $writes, 'hold_ms' => $holdMs], JSON_THROW_ON_ERROR));
        // @phpstan-ignore frankenphp.classic.noUnlimitedIoTimeout (test helper: the child bounds every lock wait to 10 s and is released in tearDown)
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/Fixtures/hold-lock.php', $job], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes);
        self::assertIsResource($process);
        $this->process = $process;
        $this->pipes   = [$pipes[0], $pipes[1]];
        self::assertSame('locked', trim((string) fgets($pipes[1])), 'the second session holds its locks');
    }

    private function releaseChild(): void
    {
        if ($this->pipes === null || !is_resource($this->process)) {
            return;
        }
        fwrite($this->pipes[0], "go\n");
        fclose($this->pipes[0]);
        $done = trim((string) fgets($this->pipes[1]));
        fclose($this->pipes[1]);
        $status        = proc_close($this->process);
        $this->pipes   = null;
        $this->process = null;
        self::assertSame('done', $done);
        self::assertSame(0, $status, 'the second session committed');
    }

    private function liveRows(): int
    {
        $owner = $this->host()->owner('alice');

        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM ' . $this->table() . ' WHERE owner_type = ? AND owner_id = ?', [$owner->type, $owner->id]);
    }

    private function time(DateTimeImmutable $time): string
    {
        return $time->format($this->connection()->getDatabasePlatform()->getDateTimeFormatString());
    }

    private function service(string $id): object
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);
        $service = $container->get($id);
        self::assertIsObject($service);

        return $service;
    }

    private function connection(): Connection
    {
        return $this->host()->entityManager($this->kernel)->getConnection();
    }

    private function table(): string
    {
        return $this->host()->tablePrefix() . 'api_token';
    }
}
