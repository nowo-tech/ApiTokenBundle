<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Unit\Application;

use Nowo\ApiTokenBundle\Application\Default\ConfigSettingsProvider;
use Nowo\ApiTokenBundle\Application\Token\TokenCodec;
use Nowo\ApiTokenBundle\Contract\ApiTokenPrincipal;
use Nowo\ApiTokenBundle\Contract\AuthFailure;
use Nowo\ApiTokenBundle\Contract\Event\ApiTokenUsedEvent;
use Nowo\ApiTokenBundle\Contract\Grants;
use Nowo\ApiTokenBundle\Contract\IssueRequest;
use Nowo\ApiTokenBundle\Contract\Reach;
use Nowo\ApiTokenBundle\Domain\ApiToken;
use RuntimeException;

use function str_repeat;
use function strlen;
use function substr;

/**
 * Verification order and timing guard (contract §6.2, §11 "Timing guard", "Status").
 */
final class AuthenticatorTest extends ServiceTestCase
{
    public function testAValidTokenAuthenticatesWithItsOwnerGrantsAndReach(): void
    {
        $owner  = $this->addUser('42');
        $issued = $this->issuer()->issue(new IssueRequest($owner, 'CI', new Grants(['tasks' => 'tasks', 'mcp' => 'on'], ['mcp' => ['destructive']]), Reach::everything(), 30));
        $this->clearEvents();

        $principal = $this->authenticator()->authenticate($issued->secret());

        self::assertInstanceOf(ApiTokenPrincipal::class, $principal);
        self::assertTrue($principal->owner->equals($owner));
        self::assertSame($this->users['user:42'], $principal->user);
        self::assertSame(['mcp' => 'on', 'tasks' => 'tasks'], $principal->grants->levels);
        self::assertTrue($principal->grants->hasFlag('mcp', 'destructive'));
        self::assertSame('CI', $principal->tokenName);
        self::assertSame($issued->token->id->value, $principal->tokenId->value);
        self::assertEquals($issued->token->expiresAt, $principal->expiresAt);
        self::assertCount(1, $this->events);
        self::assertInstanceOf(ApiTokenUsedEvent::class, $this->events[0]);
        self::assertNull($this->events[0]->module);
    }

    public function testMalformedAndMissingNeverReadTheDatabase(): void
    {
        $authenticator = $this->authenticator();

        self::assertSame(AuthFailure::Missing, $authenticator->authenticate(''));
        self::assertSame(AuthFailure::Malformed, $authenticator->authenticate('nwt_nope'));
        self::assertSame(AuthFailure::Malformed, $authenticator->authenticate(str_repeat('a', 4096)));
        self::assertSame(0, $this->store->lookups);
        self::assertSame([], $this->hasher->compared);
    }

    public function testUnknownAndWrongSecretBothRunTheConstantTimeComparison(): void
    {
        $owner                  = $this->addUser('42');
        $issued                 = $this->issue($owner);
        $this->hasher->compared = [];

        $unknown = $this->codec->generate()->token();
        self::assertSame(AuthFailure::Unknown, $this->authenticator()->authenticate($unknown));
        self::assertSame([str_repeat('0', 64)], $this->hasher->compared, 'a miss compares against the fixed dummy');

        $this->hasher->compared = [];
        $body                   = substr($issued->secret(), 0, -6);
        $tampered               = substr($body, 0, -1) . ($body[strlen($body) - 1] === 'A' ? 'B' : 'A');
        $tampered .= TokenCodec::checksum($tampered);
        self::assertSame(AuthFailure::WrongSecret, $this->authenticator()->authenticate($tampered));
        self::assertCount(1, $this->hasher->compared, 'a mismatch compares against the stored hash');
        self::assertNotSame(str_repeat('0', 64), $this->hasher->compared[0]);
    }

    public function testAStrippedChecksumIsAWrongSecret(): void
    {
        $issued = $this->issue($this->addUser('42'));

        self::assertSame(AuthFailure::WrongSecret, $this->authenticator()->authenticate(substr($issued->secret(), 0, -6)));
    }

    public function testStatusChecksInContractOrder(): void
    {
        $owner  = $this->addUser('42');
        $issued = $this->issue($owner, 'CI', ['tasks' => 'read'], 1);

        $this->inactive['user:42'] = true;
        self::assertSame(AuthFailure::OwnerInactive, $this->authenticator()->authenticate($issued->secret()));
        self::assertSame(AuthFailure::Disabled, $this->authenticator(new ConfigSettingsProvider(false))->authenticate($issued->secret()), 'the module switch is checked before the owner');

        unset($this->users['user:42']);
        self::assertSame(AuthFailure::OwnerInactive, $this->authenticator()->authenticate($issued->secret()), 'erased owner');

        $this->clock->modify('+1 day');
        self::assertSame(AuthFailure::Expired, $this->authenticator(new ConfigSettingsProvider(false))->authenticate($issued->secret()), 'expiry is checked before the switch');
    }

    public function testModuleSwitchesNarrowTheGrants(): void
    {
        $issued    = $this->issue($this->addUser('42'), 'CI', ['tasks' => 'read', 'wiki' => 'edit', 'mcp' => 'on']);
        $principal = $this->authenticator(new ConfigSettingsProvider(true, ['wiki' => false, 'tasks' => false]))->authenticate($issued->secret());

        self::assertInstanceOf(ApiTokenPrincipal::class, $principal);
        self::assertSame([], $principal->grants->levels, 'mcp falls with its required modules');
    }

    public function testLastUsedIsThrottledAndItsFailureIgnored(): void
    {
        $owner  = $this->addUser('42');
        $issued = $this->issue($owner);
        $this->clearEvents();

        $this->store->touchResult = false;
        self::assertInstanceOf(ApiTokenPrincipal::class, $this->authenticator()->authenticate($issued->secret()));
        self::assertSame([], $this->events, 'no event when nothing was written');

        $this->store->touchFailure = new RuntimeException('database busy');
        self::assertInstanceOf(ApiTokenPrincipal::class, $this->authenticator()->authenticate($issued->secret()));

        $token                                          = $this->store->tokens[$issued->token->id->value];
        $this->store->tokens[$issued->token->id->value] = new ApiToken(
            $token->id()->value,
            $token->lookupId()->value,
            $token->secretHash(),
            $token->hashVersion(),
            'user',
            '42',
            'CI',
            $token->grants()->toArray(),
            true,
            [],
            $token->createdAt(),
            $token->expiresAt(),
            $this->clock->now()->modify('-30 seconds'),
        );
        $this->store->touchFailure = null;
        $this->store->touchResult  = true;
        $this->authenticator()->authenticate($issued->secret());
        self::assertSame([], $this->events, 'used 30 s ago: no write, no event');
    }
}
