<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Unit\Application;

use Nowo\ApiTokenBundle\Application\Default\ConfigSettingsProvider;
use Nowo\ApiTokenBundle\Contract\Event\ApiTokenIssuedEvent;
use Nowo\ApiTokenBundle\Contract\Exception\ApiTokenIssueRefusedException;
use Nowo\ApiTokenBundle\Contract\Exception\InvalidApiTokenArgumentException;
use Nowo\ApiTokenBundle\Contract\Grants;
use Nowo\ApiTokenBundle\Contract\IssueRequest;
use Nowo\ApiTokenBundle\Contract\OwnerRef;
use Nowo\ApiTokenBundle\Contract\Reach;
use Nowo\ApiTokenBundle\Domain\LookupIdTakenException;
use PHPUnit\Framework\Attributes\DataProvider;

use function hash;
use function json_encode;
use function serialize;
use function str_repeat;

/**
 * Issue rules (contract §6.1, §6.4–§6.6, Q-O5).
 */
final class IssuerTest extends ServiceTestCase
{
    public function testIssueStoresOnlyTheHashAndDispatchesIdsAfterCommit(): void
    {
        $owner  = new OwnerRef('user', '42');
        $issued = $this->issue($owner, '  Deploy bot  ', ['tasks' => 'full'], 90);

        self::assertSame('Deploy bot', $issued->token->name, 'trimmed');
        self::assertSame('2027-02-10 10:00:00', $issued->token->expiresAt->format('Y-m-d H:i:s'));
        self::assertSame('2026-11-12 10:00:00', $issued->token->createdAt->format('Y-m-d H:i:s'));
        self::assertNull($issued->token->lastUsedAt);
        self::assertSame('account', $issued->token->createdVia);
        $stored = $this->store->tokens[$issued->token->id->value];
        self::assertSame(hash('sha256', $issued->secret()), $stored->secretHash());
        self::assertSame(1, $stored->hashVersion());

        self::assertCount(1, $this->events);
        $event = $this->events[0];
        self::assertInstanceOf(ApiTokenIssuedEvent::class, $event);
        self::assertSame($issued->token->id->value, $event->tokenId->value);
        self::assertSame('everything', $event->reachSummary);
        self::assertSame('account', $event->createdVia);
        self::assertStringNotContainsString($issued->secret(), serialize($event));
        self::assertStringNotContainsString($stored->secretHash(), serialize($event));
    }

    public function testALookupCollisionIsRetried(): void
    {
        $this->store->takenLookups = 2;
        $issued                    = $this->issue(new OwnerRef('user', '42'));

        self::assertArrayHasKey($issued->token->id->value, $this->store->tokens);
    }

    public function testThreeCollisionsGiveUp(): void
    {
        $this->store->takenLookups = 3;

        $this->expectException(LookupIdTakenException::class);
        $this->issue(new OwnerRef('user', '42'));
    }

    public function testLimitedReachWithinTheChoices(): void
    {
        $issuer = $this->issuer(['reach' => self::reachChoices(['p1', 'wiki:docs']), 'allowEverything' => false]);
        $issued = $issuer->issue(new IssueRequest(new OwnerRef('user', '1'), 'n', new Grants(['wiki' => 'read']), Reach::limitedTo(['wiki:docs']), 7));

        self::assertSame(['wiki:docs'], $issued->token->reach->allEntries());
        self::assertSame(1, $this->events[0] instanceof ApiTokenIssuedEvent ? $this->events[0]->reachSummary : null);
    }

    public function testCapAndDuplicateNameRefusals(): void
    {
        $owner = new OwnerRef('user', '42');
        $this->issuer(['maxPerOwner' => 2])->issue(new IssueRequest($owner, 'One', new Grants(['tasks' => 'read']), Reach::everything(), 1));

        try {
            $this->issuer(['maxPerOwner' => 2])->issue(new IssueRequest($owner, 'ONE', new Grants(['tasks' => 'read']), Reach::everything(), 1));
            self::fail('duplicate name accepted');
        } catch (ApiTokenIssueRefusedException $refusal) {
            self::assertSame(ApiTokenIssueRefusedException::DUPLICATE_NAME, $refusal->reason);
        }
        $this->issuer(['maxPerOwner' => 2])->issue(new IssueRequest(new OwnerRef('user', '43'), 'One', new Grants(['tasks' => 'read']), Reach::everything(), 1));
        $this->issuer(['maxPerOwner' => 2])->issue(new IssueRequest($owner, 'Two', new Grants(['tasks' => 'read']), Reach::everything(), 1));

        try {
            $this->issuer(['maxPerOwner' => 2])->issue(new IssueRequest($owner, 'Three', new Grants(['tasks' => 'read']), Reach::everything(), 1));
            self::fail('cap ignored');
        } catch (ApiTokenIssueRefusedException $refusal) {
            self::assertSame(ApiTokenIssueRefusedException::TOO_MANY, $refusal->reason);
        }

        $this->clock->modify('+2 days');
        $this->issuer(['maxPerOwner' => 2])->issue(new IssueRequest($owner, 'Three', new Grants(['tasks' => 'read']), Reach::everything(), 1));
        self::assertCount(4, $this->store->tokens, 'expired tokens do not count against the cap');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, IssueRequest, string}>
     */
    public static function refusals(): iterable
    {
        $owner = new OwnerRef('user', '1');
        $tasks = new Grants(['tasks' => 'read']);

        yield 'switched off' => [['settings' => new ConfigSettingsProvider(false)], new IssueRequest($owner, 'n', $tasks, Reach::everything(), 1), ApiTokenIssueRefusedException::DISABLED];
        yield 'policy' => [['policy' => self::policy(false)], new IssueRequest($owner, 'n', $tasks, Reach::everything(), 1), ApiTokenIssueRefusedException::POLICY];
        yield 'no module' => [[], new IssueRequest($owner, 'n', new Grants([]), Reach::everything(), 1), ApiTokenIssueRefusedException::GRANTS];
        yield 'unknown level' => [[], new IssueRequest($owner, 'n', new Grants(['tasks' => 'admin']), Reach::everything(), 1), ApiTokenIssueRefusedException::GRANTS];
        yield 'module not in plan' => [['policy' => self::policy(true, ['wiki'])], new IssueRequest($owner, 'n', $tasks, Reach::everything(), 1), ApiTokenIssueRefusedException::GRANTS];
        yield 'module switched off' => [['settings' => new ConfigSettingsProvider(true, ['tasks' => false])], new IssueRequest($owner, 'n', $tasks, Reach::everything(), 1), ApiTokenIssueRefusedException::GRANTS];
        yield 'mcp alone' => [[], new IssueRequest($owner, 'n', new Grants(['mcp' => 'on']), Reach::everything(), 1), ApiTokenIssueRefusedException::GRANTS];
        yield 'everything not allowed' => [['allowEverything' => false], new IssueRequest($owner, 'n', $tasks, Reach::everything(), 1), ApiTokenIssueRefusedException::REACH];
        yield 'too many exceptions' => [['maxReachEntries' => 1], new IssueRequest($owner, 'n', $tasks, Reach::everything(['a', 'b']), 1), ApiTokenIssueRefusedException::REACH];
        yield 'empty reach' => [[], new IssueRequest($owner, 'n', $tasks, Reach::limitedTo([]), 1), ApiTokenIssueRefusedException::REACH];
        yield 'too many entries' => [['maxReachEntries' => 1, 'reach' => self::reachChoices(['a', 'b'])], new IssueRequest($owner, 'n', $tasks, Reach::limitedTo(['a', 'b']), 1), ApiTokenIssueRefusedException::REACH];
        yield 'entry not a choice' => [['reach' => self::reachChoices(['a'])], new IssueRequest($owner, 'n', $tasks, Reach::limitedTo(['a', 'other-org']), 1), ApiTokenIssueRefusedException::REACH];
        yield 'zero days' => [[], new IssueRequest($owner, 'n', $tasks, Reach::everything(), 0), ApiTokenIssueRefusedException::EXPIRY];
        yield 'above 365 days' => [['maxDays' => 400, 'policy' => self::policy(true, null, 1000)], new IssueRequest($owner, 'n', $tasks, Reach::everything(), 366), ApiTokenIssueRefusedException::EXPIRY];
        yield 'above config max' => [['maxDays' => 30], new IssueRequest($owner, 'n', $tasks, Reach::everything(), 31), ApiTokenIssueRefusedException::EXPIRY];
        yield 'above policy max' => [['policy' => self::policy(true, null, 7)], new IssueRequest($owner, 'n', $tasks, Reach::everything(), 8), ApiTokenIssueRefusedException::EXPIRY];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('refusals')]
    public function testRefusals(array $options, IssueRequest $request, string $reason): void
    {
        try {
            $this->issuer($options)->issue($request);
            self::fail('refusal expected');
        } catch (ApiTokenIssueRefusedException $refusal) {
            self::assertSame($reason, $refusal->reason);
        }
        self::assertSame([], $this->store->tokens);
        self::assertSame([], $this->events);
    }

    public function testTheOwnerMustExistAndBeActive(): void
    {
        $this->missing                    = ['user:ghost'];
        $inactive                         = $this->addUser('off');
        $this->inactive[$inactive->key()] = true;

        foreach ([new OwnerRef('user', 'ghost'), $inactive] as $owner) {
            try {
                $this->issue($owner);
                self::fail('refusal expected');
            } catch (ApiTokenIssueRefusedException $refusal) {
                self::assertSame(ApiTokenIssueRefusedException::OWNER, $refusal->reason);
            }
        }
        self::assertSame([], $this->store->tokens);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function malformed(): iterable
    {
        yield 'empty name' => ['   ', 'account'];
        yield 'long name' => [str_repeat('n', 81), 'account'];
        yield 'control char' => ["a\tb", 'account'];
        yield 'bad channel' => ['n', 'Account'];
        yield 'long channel' => ['n', str_repeat('a', 17)];
    }

    #[DataProvider('malformed')]
    public function testMalformedRequests(string $name, string $via): void
    {
        $this->expectException(InvalidApiTokenArgumentException::class);
        $this->issuer()->issue(new IssueRequest(new OwnerRef('user', '1'), $name, new Grants(['tasks' => 'read']), Reach::everything(), 1, $via));
    }

    public function testTheSecretNeverReachesTheStoredRow(): void
    {
        $issued = $this->issue(new OwnerRef('user', '42'));
        $row    = $this->store->tokens[$issued->token->id->value];

        self::assertStringNotContainsString($issued->secret(), serialize($row));
        self::assertStringNotContainsString($issued->secret(), (string) json_encode($issued->token));
    }
}
