<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Integration\Database;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Types\Types;
use Nowo\ApiTokenBundle\Application\Token\TokenCodec;
use Nowo\ApiTokenBundle\Contract\ApiTokenAuthenticatorInterface;
use Nowo\ApiTokenBundle\Contract\ApiTokenIssuerInterface;
use Nowo\ApiTokenBundle\Contract\ApiTokenPrincipal;
use Nowo\ApiTokenBundle\Contract\ApiTokenQueryInterface;
use Nowo\ApiTokenBundle\Contract\ApiTokenRevokerInterface;
use Nowo\ApiTokenBundle\Contract\ApiTokenView;
use Nowo\ApiTokenBundle\Contract\AuthFailure;
use Nowo\ApiTokenBundle\Contract\Event\ApiTokenIssuedEvent;
use Nowo\ApiTokenBundle\Contract\Event\ApiTokenRevokedEvent;
use Nowo\ApiTokenBundle\Contract\Event\ApiTokenUsedEvent;
use Nowo\ApiTokenBundle\Contract\Exception\ApiTokenIssueRefusedException;
use Nowo\ApiTokenBundle\Contract\Grants;
use Nowo\ApiTokenBundle\Contract\IssuedToken;
use Nowo\ApiTokenBundle\Contract\IssueRequest;
use Nowo\ApiTokenBundle\Contract\OwnerRef;
use Nowo\ApiTokenBundle\Contract\Reach;
use Nowo\ApiTokenBundle\Contract\RevocationReason;
use Nowo\ApiTokenBundle\Contract\TokenId;
use Nowo\ApiTokenBundle\Domain\ApiTokenStoreInterface;
use Nowo\ApiTokenBundle\Infrastructure\Persistence\AfterCommitQueue;
use Nowo\ApiTokenBundle\Tests\Fixtures\Ddd\Member;
use Nowo\ApiTokenBundle\Tests\Fixtures\QueryLog;
use Nowo\ApiTokenBundle\Tests\Fixtures\RecordingListener;
use Nowo\ApiTokenBundle\Tests\Fixtures\Standard\User;
use Nowo\ApiTokenBundle\Tests\Integration\Kernel\Host;
use Nowo\ApiTokenBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\ApiTokenBundle\Tests\Integration\Kernel\TestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerInterface;

use function hash;
use function json_encode;
use function serialize;
use function str_contains;
use function strlen;
use function substr;
use function trim;

/**
 * The S1 functional suite, run once per host kind (standard and DDD) on the configured database (SQLite locally and
 * in the coverage job; MySQL 8.4 and PostgreSQL 17 in the CI database job).
 */
abstract class CoreFunctionalTestCase extends KernelTestCase
{
    protected TestKernel $kernel;

    abstract protected function host(): Host;

    /**
     * @param array<string, mixed> $config
     */
    protected function boot(array $config = []): void
    {
        $host         = $this->host();
        $this->kernel = $this->bootKernel($host->bundleConfig($config), Host::databaseUrl(), $host->configure());
        $host->resetSchema($this->kernel);
        $host->createUser($this->kernel, 'alice');
        $host->createUser($this->kernel, 'bob');
        $this->listener()->clear();
        $this->queries()->clear();
    }

    protected function setUp(): void
    {
        $this->boot();
    }

    protected function service(string $id): object
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);
        $service = $container->get($id);
        self::assertIsObject($service);

        return $service;
    }

    protected function issuer(): ApiTokenIssuerInterface
    {
        $service = $this->service(ApiTokenIssuerInterface::class);
        self::assertInstanceOf(ApiTokenIssuerInterface::class, $service);

        return $service;
    }

    protected function authenticator(): ApiTokenAuthenticatorInterface
    {
        $service = $this->service(ApiTokenAuthenticatorInterface::class);
        self::assertInstanceOf(ApiTokenAuthenticatorInterface::class, $service);

        return $service;
    }

    protected function revoker(): ApiTokenRevokerInterface
    {
        $service = $this->service(ApiTokenRevokerInterface::class);
        self::assertInstanceOf(ApiTokenRevokerInterface::class, $service);

        return $service;
    }

    protected function query(): ApiTokenQueryInterface
    {
        $service = $this->service(ApiTokenQueryInterface::class);
        self::assertInstanceOf(ApiTokenQueryInterface::class, $service);

        return $service;
    }

    protected function listener(): RecordingListener
    {
        $service = $this->service(RecordingListener::class);
        self::assertInstanceOf(RecordingListener::class, $service);

        return $service;
    }

    protected function queries(): QueryLog
    {
        $service = $this->service(QueryLog::class);
        self::assertInstanceOf(QueryLog::class, $service);

        return $service;
    }

    protected function clock(): MockClock
    {
        $service = $this->service('clock');
        self::assertInstanceOf(MockClock::class, $service);

        return $service;
    }

    protected function connection(): Connection
    {
        return $this->host()->entityManager($this->kernel)->getConnection();
    }

    protected function table(): string
    {
        return $this->host()->tablePrefix() . 'api_token';
    }

    /**
     * @param array<string, string> $levels
     */
    protected function issue(string $ownerId = 'alice', string $name = 'CI', array $levels = ['tasks' => 'read'], int $days = 30): IssuedToken
    {
        return $this->issuer()->issue(new IssueRequest($this->host()->owner($ownerId), $name, new Grants($levels), Reach::everything(), $days));
    }

    public function testIssueAuthenticateAndLastUsed(): void
    {
        $issued = $this->issue('alice', 'CI', ['tasks' => 'tasks', 'wiki' => 'read']);
        self::assertStringStartsWith('nwt_', $issued->secret);

        $issuedEvents = $this->listener()->of(ApiTokenIssuedEvent::class);
        self::assertCount(1, $issuedEvents);
        self::assertSame($issued->token->id->value, $issuedEvents[0]->tokenId->value);

        $principal = $this->authenticator()->authenticate($issued->secret);
        self::assertInstanceOf(ApiTokenPrincipal::class, $principal);
        self::assertTrue($principal->owner->equals($this->host()->owner('alice')));
        self::assertSame(['tasks' => 'tasks', 'wiki' => 'read'], $principal->grants->levels);
        self::assertTrue($principal->reach->isEverything());
        $user = $principal->user;
        self::assertSame('alice', $user instanceof Member ? $user->memberId() : ($user instanceof User ? $user->getUserIdentifier() : null));
        self::assertCount(1, $this->listener()->of(ApiTokenUsedEvent::class));
        self::assertSame(Host::NOW, $this->query()->find($issued->token->id)?->lastUsedAt?->format('Y-m-d H:i:s'));

        $this->clock()->modify('+30 seconds');
        self::assertInstanceOf(ApiTokenPrincipal::class, $this->authenticator()->authenticate($issued->secret));
        self::assertCount(1, $this->listener()->of(ApiTokenUsedEvent::class), 'throttled: no second write within 60 s');
        $this->clock()->modify('+31 seconds');
        self::assertInstanceOf(ApiTokenPrincipal::class, $this->authenticator()->authenticate($issued->secret));
        self::assertCount(2, $this->listener()->of(ApiTokenUsedEvent::class));

        foreach ($this->listener()->events as $event) {
            self::assertStringNotContainsString($issued->secret, serialize($event));
        }
    }

    public function testTheHashIsStoredAndNeverQueried(): void
    {
        $issued = $this->issue();
        $row    = $this->connection()->fetchAssociative('SELECT secret_hash, hash_version, lookup_id FROM ' . $this->table());
        self::assertIsArray($row);
        self::assertSame(hash('sha256', $issued->secret), $row['secret_hash']);
        self::assertSame(1, (int) $row['hash_version']);
        self::assertSame($issued->token->lookupId->value, $row['lookup_id']);
        self::assertFalse(str_contains((string) json_encode($row), substr($issued->secret, -49)), 'the secret is not stored');

        $this->queries()->clear();
        $this->authenticator()->authenticate($issued->secret);
        $this->authenticator()->authenticate($this->tampered($issued->secret));
        foreach ($this->queries()->queries as $sql) {
            self::assertStringNotContainsString('secret_hash =', $sql);
            self::assertStringNotContainsString('secret_hash=', $sql);
        }
    }

    public function testFailuresAreClassifiedAndMalformedNeverQueries(): void
    {
        $issued = $this->issue();
        $codec  = new TokenCodec('nwt_');

        $this->queries()->clear();
        self::assertSame(AuthFailure::Malformed, $this->authenticator()->authenticate($issued->secret . "\n"));
        self::assertSame(AuthFailure::Malformed, $this->authenticator()->authenticate('bt_' . substr($issued->secret, 4)));
        self::assertSame([], $this->queries()->queries, 'no query for malformed tokens');

        self::assertSame(AuthFailure::Unknown, $this->authenticator()->authenticate($codec->generate()->token));
        self::assertSame(AuthFailure::WrongSecret, $this->authenticator()->authenticate($this->tampered($issued->secret)));
    }

    public function testExpiredAndInactiveOwners(): void
    {
        $alice = $this->issue('alice', 'short', ['tasks' => 'read'], 1);
        $bob   = $this->issue('bob');

        $this->host()->deactivate($this->kernel, 'bob');
        self::assertSame(AuthFailure::OwnerInactive, $this->authenticator()->authenticate($bob->secret));
        self::assertNotNull($this->query()->find($bob->token->id), 'a disabled account changes no row (Q-B7)');

        $this->host()->deleteUser($this->kernel, 'bob');
        self::assertSame(AuthFailure::OwnerInactive, $this->authenticator()->authenticate($bob->secret), 'erased owner');

        $this->clock()->modify('+1 day');
        self::assertSame(AuthFailure::Expired, $this->authenticator()->authenticate($alice->secret));
    }

    public function testRevocationPaths(): void
    {
        $one   = $this->issue('alice', 'one');
        $two   = $this->issue('alice', 'two');
        $three = $this->issue('alice', 'three');
        $bobs  = $this->issue('bob', 'bob');
        $this->listener()->clear();

        self::assertFalse($this->revoker()->revoke($this->host()->owner('bob'), $one->token->id, RevocationReason::Owner), 'not the owner');
        self::assertTrue($this->revoker()->revoke($this->host()->owner('alice'), $one->token->id, RevocationReason::Owner));
        self::assertSame(AuthFailure::Unknown, $this->authenticator()->authenticate($one->secret), 'revoked = unknown');
        self::assertTrue($this->revoker()->revokeAsOperator($two->token->id, $this->host()->owner('bob')));
        self::assertSame(1, $this->revoker()->revokeAll($this->host()->owner('alice'), RevocationReason::OwnerAll));
        self::assertSame(AuthFailure::Unknown, $this->authenticator()->authenticate($three->secret));
        self::assertInstanceOf(ApiTokenPrincipal::class, $this->authenticator()->authenticate($bobs->secret));

        $events = $this->listener()->of(ApiTokenRevokedEvent::class);
        self::assertCount(3, $events);
        self::assertSame([RevocationReason::Owner, RevocationReason::Operator, RevocationReason::OwnerAll], [$events[0]->reason, $events[1]->reason, $events[2]->reason]);
        self::assertSame($this->host()->owner('bob')->key(), $events[1]->actor?->key());
        self::assertCount(3, $this->listener()->revoked, 'revocation listeners ran');
    }

    public function testOwnerIdsCompareExactlyInTheDatabase(): void
    {
        $upper = $this->issuer()->issue(new IssueRequest($this->host()->owner('UserA'), 'n', new Grants(['tasks' => 'read']), Reach::everything(), 1));
        $lower = $this->issuer()->issue(new IssueRequest($this->host()->owner('usera'), 'n', new Grants(['tasks' => 'read']), Reach::everything(), 1));

        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM ' . $this->table() . ' WHERE owner_id = ?', ['usera']), 'binary collation');
        self::assertSame([$lower->token->id->value], $this->ids($this->query()->ofOwner($this->host()->owner('usera'))));
        self::assertSame([$upper->token->id->value], $this->ids($this->query()->ofOwner($this->host()->owner('UserA'))));
        self::assertFalse($this->revoker()->revoke($this->host()->owner('usera'), $upper->token->id, RevocationReason::Owner));
        self::assertSame(1, $this->revoker()->revokeAll($this->host()->owner('usera'), RevocationReason::Erasure));
        self::assertNotNull($this->query()->find($upper->token->id));
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM ' . $this->table() . ' WHERE lookup_id = ?', [$upper->token->lookupId->value]));
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM ' . $this->table() . ' WHERE lookup_id = ?', [strtoupper($upper->token->lookupId->value)]));
    }

    public function testStoredGrantsAreNarrowedNeverWidened(): void
    {
        $issued = $this->issue('alice', 'CI', ['tasks' => 'read']);
        $this->connection()->update($this->table(), ['grants' => '{"levels":{"tasks":"read","ghost":"full","wiki":"superuser"},"flags":{"tasks":["x"]}}'], ['id' => $issued->token->id->value]);

        $principal = $this->authenticator()->authenticate($issued->secret);
        self::assertInstanceOf(ApiTokenPrincipal::class, $principal);
        self::assertSame(['tasks' => 'read'], $principal->grants->levels);
        self::assertSame([], $principal->grants->flags);

        // Valid JSON of the wrong shape (MySQL and PostgreSQL refuse invalid JSON text; the unit suite covers it).
        $this->connection()->update($this->table(), ['grants' => '"damaged"', 'reach_everything' => 1, 'reach' => '["bad entry"]'], ['id' => $issued->token->id->value], ['reach_everything' => Types::BOOLEAN]);
        $principal = $this->authenticator()->authenticate($issued->secret);
        self::assertInstanceOf(ApiTokenPrincipal::class, $principal);
        self::assertTrue($principal->grants->isEmpty());
        self::assertFalse($principal->reach->isEverything(), 'damaged reach fails closed');
    }

    public function testADamagedRowNeverAuthenticates(): void
    {
        $issued = $this->issue();
        $this->connection()->update($this->table(), ['owner_type' => 'Bad Type'], ['id' => $issued->token->id->value]);

        self::assertSame(AuthFailure::Unknown, $this->authenticator()->authenticate($issued->secret));
        self::assertNull($this->query()->find($issued->token->id));
    }

    public function testWritesJoinAHostTransaction(): void
    {
        $connection = $this->connection();
        $queue      = $this->service(AfterCommitQueue::class);
        self::assertInstanceOf(AfterCommitQueue::class, $queue);

        $connection->beginTransaction();
        $issued = $this->issue();
        self::assertSame([], $this->listener()->of(ApiTokenIssuedEvent::class), 'held until the host commits');
        self::assertSame(1, $queue->heldCount());
        $connection->commit();
        self::assertCount(1, $this->listener()->of(ApiTokenIssuedEvent::class));
        self::assertSame(0, $queue->heldCount());

        $connection->beginTransaction();
        $rolledBack = $this->issue('alice', 'rolled back');
        $connection->rollBack();
        self::assertCount(1, $this->listener()->of(ApiTokenIssuedEvent::class), 'dropped on rollback');
        self::assertNull($this->query()->find($rolledBack->token->id));

        $connection->beginTransaction();
        self::assertTrue($this->revoker()->revoke($this->host()->owner('alice'), $issued->token->id, RevocationReason::Owner));
        self::assertSame(AuthFailure::Unknown, $this->authenticator()->authenticate($issued->secret), 'deleted inside the open transaction');
        $connection->rollBack();
        self::assertNotNull($this->query()->find($issued->token->id), 'the revocation rolled back with the host');
        self::assertSame([], $this->listener()->of(ApiTokenRevokedEvent::class));
    }

    public function testIssueRefusesBeyondTheCapAndKeepsNamesUnique(): void
    {
        $this->boot(['max_per_owner' => 2]);
        $this->issue('alice', 'one');
        try {
            $this->issue('alice', 'ONE');
            self::fail('duplicate name');
        } catch (ApiTokenIssueRefusedException $refusal) {
            self::assertSame(ApiTokenIssueRefusedException::DUPLICATE_NAME, $refusal->reason);
        }
        $this->issue('alice', 'two');
        try {
            $this->issue('alice', 'three');
            self::fail('cap');
        } catch (ApiTokenIssueRefusedException $refusal) {
            self::assertSame(ApiTokenIssueRefusedException::TOO_MANY, $refusal->reason);
        }
        self::assertCount(2, $this->query()->ofOwner($this->host()->owner('alice')));
    }

    public function testQueryPagesNewestFirst(): void
    {
        $ids = [];
        for ($i = 0; $i < 5; ++$i) {
            $ids[] = $this->issue('alice', 'n' . $i)->token->id->value;
            $this->clock()->modify('+1 second');
        }
        $this->issue('bob');

        $first = $this->query()->page(null, 3, $this->host()->owner('alice'));
        self::assertSame([$ids[4], $ids[3], $ids[2]], $this->ids($first->items));
        $second = $this->query()->page($first->nextAfter, 3, $this->host()->owner('alice'));
        self::assertSame([$ids[1], $ids[0]], $this->ids($second->items));
        self::assertNull($second->nextAfter);
        self::assertCount(6, $this->query()->page(null, 10)->items);
        self::assertSame([$ids[4], $ids[3], $ids[2], $ids[1], $ids[0]], $this->ids($this->query()->ofOwner($this->host()->owner('alice'))));
    }

    public function testCommands(): void
    {
        $application = new Application($this->kernel);
        $application->setAutoExit(false);

        $issue = new CommandTester($application->find('nowo:api-token:issue'));
        $issue->execute(['owner-id' => 'alice', '--owner-type' => $this->host()->owner('alice')->type, '--name' => 'break-glass', '--grant' => ['tasks:read', 'mcp:on'], '--flag' => ['mcp:destructive'], '--days' => '7'], ['capture_stderr_separately' => true]);
        self::assertSame(0, $issue->getStatusCode(), $issue->getErrorOutput());
        $secret = trim($issue->getDisplay());
        self::assertStringContainsString('shown only now', $issue->getErrorOutput());
        self::assertStringNotContainsString($secret, $issue->getErrorOutput());
        $principal = $this->authenticator()->authenticate($secret);
        self::assertInstanceOf(ApiTokenPrincipal::class, $principal);
        self::assertTrue($principal->grants->hasFlag('mcp', 'destructive'));
        self::assertSame('cli', $this->query()->ofOwner($this->host()->owner('alice'))[0]->createdVia);

        $limited = new CommandTester($application->find('nowo:api-token:issue'));
        self::assertSame(2, $limited->execute(['owner-id' => 'alice', '--owner-type' => $this->host()->owner('alice')->type, '--name' => 'x', '--grant' => ['tasks:read'], '--reach' => ['p1']]), 'no reach choices by default');
        self::assertSame(2, $limited->execute(['owner-id' => 'alice', '--name' => 'x', '--grant' => ['tasks'], '--days' => '7']));
        self::assertSame(2, $limited->execute(['owner-id' => 'alice', '--name' => 'x', '--flag' => ['tasks'], '--days' => '7']));
        self::assertSame(2, $limited->execute(['owner-id' => 'alice', '--name' => 'x', '--grant' => ['tasks:read'], '--days' => 'ten']));

        $this->issue('bob', 'old', ['tasks' => 'read'], 1);
        $this->clock()->modify('+40 days');
        $purge = new CommandTester($application->find('nowo:api-token:purge-expired'));
        self::assertSame(0, $purge->execute([]));
        self::assertStringContainsString('Purged 2 expired API token(s).', $purge->getDisplay());
        $purged = $this->listener()->of(ApiTokenRevokedEvent::class);
        self::assertCount(2, $purged);
        self::assertSame(RevocationReason::Expired, $purged[0]->reason);
    }

    /**
     * @param list<ApiTokenView> $views
     *
     * @return list<string>
     */
    private function ids(array $views): array
    {
        $ids = [];
        foreach ($views as $view) {
            $ids[] = $view->id->value;
        }

        return $ids;
    }

    private function tampered(string $token): string
    {
        $body     = substr($token, 0, -6);
        $tampered = substr($body, 0, -1) . ($body[strlen($body) - 1] === 'A' ? 'B' : 'A');

        return $tampered . TokenCodec::checksum($tampered);
    }

    protected function store(): ApiTokenStoreInterface
    {
        $store = $this->service(ApiTokenStoreInterface::class);
        self::assertInstanceOf(ApiTokenStoreInterface::class, $store);

        return $store;
    }

    protected function isMySql(): bool
    {
        return $this->connection()->getDatabasePlatform() instanceof AbstractMySQLPlatform;
    }

    protected function now(): DateTimeImmutable
    {
        return $this->clock()->now();
    }

    protected function anyTokenId(): TokenId
    {
        return new TokenId('01933b1c-5d2e-7a3b-8c4d-123456789abc');
    }

    protected function ownerRef(string $id): OwnerRef
    {
        return $this->host()->owner($id);
    }
}
