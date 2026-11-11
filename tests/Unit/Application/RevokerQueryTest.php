<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Unit\Application;

use ArrayObject;
use Nowo\ApiTokenBundle\Application\Service\ApiTokenQuery;
use Nowo\ApiTokenBundle\Application\Service\ApiTokenRevoker;
use Nowo\ApiTokenBundle\Contract\ApiTokenView;
use Nowo\ApiTokenBundle\Contract\Event\ApiTokenRevokedEvent;
use Nowo\ApiTokenBundle\Contract\Exception\InvalidApiTokenArgumentException;
use Nowo\ApiTokenBundle\Contract\Grants;
use Nowo\ApiTokenBundle\Contract\IssueRequest;
use Nowo\ApiTokenBundle\Contract\OwnerRef;
use Nowo\ApiTokenBundle\Contract\Reach;
use Nowo\ApiTokenBundle\Contract\RevocationReason;
use Nowo\ApiTokenBundle\Contract\TokenId;
use Nowo\ApiTokenBundle\Contract\TokenRevocationListenerInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

use function array_keys;

final class RevokerQueryTest extends ServiceTestCase
{
    public function testRevokeChecksTheOwnerExactly(): void
    {
        $owner  = new OwnerRef('user', 'UserA');
        $issued = $this->issue($owner);
        $this->clearEvents();

        self::assertFalse($this->revoker()->revoke(new OwnerRef('user', 'usera'), $issued->token->id, RevocationReason::Owner));
        self::assertFalse($this->revoker()->revoke($owner, new TokenId('01933b1c-5d2e-7a3b-8c4d-123456789abc'), RevocationReason::Owner));
        self::assertCount(0, $this->events);

        self::assertTrue($this->revoker()->revoke($owner, $issued->token->id, RevocationReason::Owner));
        self::assertSame([], $this->store->tokens);
        $event = $this->events()[0] ?? null;
        self::assertInstanceOf(ApiTokenRevokedEvent::class, $event);
        self::assertSame(RevocationReason::Owner, $event->reason);
        self::assertNull($event->actor);
    }

    public function testListenersRunBeforeTheEventAndTheirFailuresAreLogged(): void
    {
        $owner  = new OwnerRef('user', '1');
        $issued = $this->issue($owner);
        /** @var ArrayObject<int, string> $calls */
        $calls   = new ArrayObject();
        $failing = new class($calls) implements TokenRevocationListenerInterface {
            /** @param ArrayObject<int, string> $calls */
            public function __construct(private ArrayObject $calls)
            {
            }

            public function onRevoked(ApiTokenView $token, RevocationReason $reason): void
            {
                $this->calls[] = 'failing';

                throw new RuntimeException('idempotency store down');
            }
        };
        $working = new class($calls) implements TokenRevocationListenerInterface {
            /** @param ArrayObject<int, string> $calls */
            public function __construct(private ArrayObject $calls)
            {
            }

            public function onRevoked(ApiTokenView $token, RevocationReason $reason): void
            {
                $this->calls[] = 'working:' . $reason->value;
            }
        };
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $errors = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->errors[] = (string) $message;
            }
        };
        $this->clearEvents();

        $revoker = new ApiTokenRevoker($this->store, [$failing, $working], $this->dispatcher(), $logger);
        self::assertTrue($revoker->revokeAsOperator($issued->token->id, new OwnerRef('user', 'admin')));
        self::assertSame(['failing', 'working:operator'], $calls->getArrayCopy());
        self::assertCount(1, $logger->errors);
        self::assertInstanceOf(ApiTokenRevokedEvent::class, $this->events[0]);
        self::assertSame('user:admin', $this->events[0]->actor?->key());
        self::assertFalse($revoker->revokeAsOperator($issued->token->id, new OwnerRef('user', 'admin')));

        $unlogged = new ApiTokenRevoker($this->store, [$failing], $this->dispatcher());
        $other    = $this->issue($owner, 'other');
        self::assertTrue($unlogged->revoke($owner, $other->token->id, RevocationReason::Compromised));
    }

    public function testRevokeAllWorksInBatches(): void
    {
        $owner = new OwnerRef('user', '1');
        for ($i = 0; $i < 3; ++$i) {
            $this->issuer(['maxPerOwner' => 1000])->issue(new IssueRequest($owner, 'n' . $i, new Grants(['tasks' => 'read']), Reach::everything(), 1));
        }
        $kept = $this->issue(new OwnerRef('user', '2'));

        self::assertSame(3, $this->revoker()->revokeAll($owner, RevocationReason::OwnerAll));
        self::assertSame(0, $this->revoker()->revokeAll($owner, RevocationReason::OwnerAll));
        self::assertSame([$kept->token->id->value], array_keys($this->store->tokens));
    }

    public function testPurgeStopsWhenAskedOrDone(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->issue(new OwnerRef('user', (string) $i), 'n', ['tasks' => 'read'], 1);
        }
        $cutoff = $this->clock->now()->modify('+3 days');

        self::assertSame(2, $this->revoker()->purgeExpired($cutoff, 2, static fn (): bool => false));
        self::assertSame(3, $this->revoker()->purgeExpired($cutoff, 2));
        self::assertSame(0, $this->revoker()->purgeExpired($cutoff, 2));
        $reasons = [];
        foreach ($this->events as $event) {
            if ($event instanceof ApiTokenRevokedEvent) {
                $reasons[] = $event->reason;
            }
        }
        self::assertCount(5, $reasons);
        self::assertSame(RevocationReason::Expired, $reasons[0]);
    }

    public function testPurgeRemovesDamagedRowsAndPagesPastThem(): void
    {
        // Audit LW-I8: damaged rows at the head of the batch neither stall the purge nor survive it.
        $old = $this->clock->now()->modify('-1 day');
        foreach (['00000000-damaged-a', '00000000-damaged-b', '00000000-damaged-c'] as $id) {
            $this->store->damaged[$id] = $old;
        }
        $this->issue(new OwnerRef('user', '1'), 'n', ['tasks' => 'read'], 1);
        $this->clearEvents();

        self::assertSame(4, $this->revoker()->purgeExpired($this->clock->now()->modify('+3 days'), 2));
        self::assertSame([], $this->store->damaged);
        self::assertSame([], $this->store->tokens);
        self::assertCount(1, $this->events(), 'one event: the damaged rows have no token to describe');
    }

    public function testQuery(): void
    {
        $owner = new OwnerRef('user', '1');
        $ids   = [];
        for ($i = 0; $i < 5; ++$i) {
            $ids[] = $this->issue($owner, 'n' . $i)->token->id->value;
        }
        $this->issue(new OwnerRef('user', '2'));
        $query = new ApiTokenQuery($this->store, 3);

        self::assertCount(3, $query->ofOwner($owner), 'capped by max_per_owner');
        self::assertSame($ids[0], $query->find(new TokenId($ids[0]))?->id->value);
        self::assertNull($query->find(new TokenId('01933b1c-5d2e-7a3b-8c4d-123456789abc')));

        $first = $query->page(null, 4);
        self::assertCount(4, $first->items);
        self::assertNotNull($first->nextAfter);
        $second = $query->page($first->nextAfter, 4);
        self::assertCount(2, $second->items);
        self::assertNull($second->nextAfter);

        $own = $query->page(null, 10, $owner);
        self::assertCount(5, $own->items);
        self::assertNull($own->nextAfter);
    }

    public function testQueryRefusesBadPages(): void
    {
        $query = new ApiTokenQuery($this->store);
        try {
            $query->page(null, 0);
            self::fail('limit 0 accepted');
        } catch (InvalidApiTokenArgumentException) {
        }
        try {
            $query->page(null, 201);
            self::fail('limit 201 accepted');
        } catch (InvalidApiTokenArgumentException) {
        }
        $this->expectException(InvalidApiTokenArgumentException::class);
        $query->page('not-an-id', 10);
    }
}
