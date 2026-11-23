<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Unit\Infrastructure\Security;

use DateTimeImmutable;
use Nowo\ApiTokenBundle\Contract\ApiTokenView;
use Nowo\ApiTokenBundle\Contract\Event\ApiTokenAccessCheckEvent;
use Nowo\ApiTokenBundle\Contract\Grants;
use Nowo\ApiTokenBundle\Contract\LookupId;
use Nowo\ApiTokenBundle\Contract\OwnerRef;
use Nowo\ApiTokenBundle\Contract\OwnerResolverInterface;
use Nowo\ApiTokenBundle\Contract\Reach;
use Nowo\ApiTokenBundle\Contract\ReauthGateInterface;
use Nowo\ApiTokenBundle\Contract\TokenId;
use Nowo\ApiTokenBundle\Infrastructure\RateLimit\SymfonyApiTokenRateLimiter;
use Nowo\ApiTokenBundle\Infrastructure\Security\ClientIpHasher;
use Nowo\ApiTokenBundle\Infrastructure\Security\ConfigurableApiTokenAccessChecker;
use Nowo\ApiTokenBundle\Infrastructure\Security\PasswordReauthGate;
use Nowo\ApiTokenBundle\Infrastructure\Security\ReauthSession;
use Nowo\ApiTokenBundle\Presentation\Routing\ApiTokenRouteLoader;
use Nowo\ApiTokenBundle\Tests\Fixtures\FakeRateLimiter;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function array_keys;
use function str_repeat;

/**
 * The S3 security pieces in isolation: access checker, access event, password gate, re-auth session, the optional
 * limits and the route loader with one area off.
 */
final class PagesSecurityTest extends TestCase
{
    public function testAnEmptyRoleListLetsAnySignedInUserThrough(): void
    {
        $authorization = $this->createMock(AuthorizationCheckerInterface::class);
        $authorization->expects(self::never())->method('isGranted');

        self::assertTrue((new ConfigurableApiTokenAccessChecker($authorization, []))->canAccess(new stdClass()));
    }

    public function testTheAccessEventDenyWinsOverAGrant(): void
    {
        $event = new ApiTokenAccessCheckEvent(new stdClass(), $this->view(), ApiTokenAccessCheckEvent::ACTION_REVOKE);
        self::assertTrue($event->isGranted());
        self::assertFalse($event->hasDecision());
        $event->grant();
        self::assertTrue($event->hasDecision());
        $event->deny();
        $event->grant();
        self::assertFalse($event->isGranted());
    }

    public function testThePasswordGateRefusesUsersWithoutAPasswordAndOverlongValues(): void
    {
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->expects(self::once())->method('isPasswordValid')->willReturn(true);
        $gate = new PasswordReauthGate($hasher);
        $user = new InMemoryUser('alice', 'x');

        self::assertFalse($gate->isFresh($user, 'sid'));
        self::assertNull($gate->form());
        self::assertFalse($gate->verify(new stdClass(), ['password' => 'x']));
        self::assertFalse($gate->verify($user, ['password' => '']));
        self::assertFalse($gate->verify($user, ['password' => str_repeat('a', PasswordReauthGate::MAX_PASSWORD_LENGTH + 1)]));
        self::assertFalse($gate->verify($user, []));
        self::assertTrue($gate->verify($user, ['password' => 'x']));
    }

    public function testTheReauthSessionEdges(): void
    {
        $owners = $this->createStub(OwnerResolverInterface::class);
        $owners->method('ownerOf')->willReturn(new OwnerRef('user', 'alice'));
        $gate = $this->createStub(ReauthGateInterface::class);
        $gate->method('isFresh')->willReturn(true);
        $user = new stdClass();

        self::assertTrue((new ReauthSession($gate, $owners, new FakeRateLimiter(), false))->isFresh(new Request(), $user), 'disabled: always fresh');
        $session = new ReauthSession($gate, $owners, new FakeRateLimiter());
        self::assertFalse($session->isFresh(new Request(), $user), 'no session: never fresh');
        self::assertSame([true, false], [$session->attempt(new Request(), $user, [])[0]->accepted, $session->attempt(new Request(), $user, [])[1]]);
        $session->forget(new Request());

        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::assertTrue($session->isFresh($request, $user), 'the gate knows a fresh step-up');
    }

    public function testIssueAndReauthLimitsAreOptionalInTheLimiter(): void
    {
        $storage = new CacheStorage(new ArrayAdapter());
        $factory = static fn (string $id): RateLimiterFactory => new RateLimiterFactory(['id' => $id, 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute'], $storage);
        $without = new SymfonyApiTokenRateLimiter($factory('t'), $factory('w'), $factory('i'), new ClientIpHasher('s'));
        self::assertTrue($without->consumeIssue(new OwnerRef('user', 'a'))->accepted);
        self::assertTrue($without->consumeReauth('sid')->accepted);

        $with = new SymfonyApiTokenRateLimiter($factory('t'), $factory('w'), $factory('i'), new ClientIpHasher('s'), $factory('issue'), $factory('reauth'));
        self::assertTrue($with->consumeIssue(new OwnerRef('user', 'a'))->accepted);
        self::assertFalse($with->consumeIssue(new OwnerRef('user', 'a'))->accepted);
        self::assertTrue($with->consumeIssue(new OwnerRef('user', 'b'))->accepted);
        self::assertTrue($with->consumeReauth('sid')->accepted);
        self::assertFalse($with->consumeReauth('sid')->accepted);
    }

    public function testTheRouteLoaderLoadsOnlyTheEnabledAreas(): void
    {
        $loader = new ApiTokenRouteLoader(['enabled' => false, 'path_prefix' => '/account'], ['enabled' => true, 'path_prefix' => '/ops/tokens']);

        self::assertTrue($loader->supports('.', ApiTokenRouteLoader::TYPE));
        self::assertFalse($loader->supports('.', 'other'));
        $routes = $loader->load('.');
        self::assertSame(['nowo_api_token_operator', 'nowo_api_token_operator_revoke'], array_keys($routes->all()));
        self::assertSame('/ops/tokens', $routes->get('nowo_api_token_operator')?->getPath());
        self::assertCount(0, (new ApiTokenRouteLoader(['enabled' => false, 'path_prefix' => '/a'], ['enabled' => false, 'path_prefix' => '/b']))->load('.'));
    }

    private function view(): ApiTokenView
    {
        $now = new DateTimeImmutable('2026-11-12');

        return new ApiTokenView(new TokenId('01935f7e-0000-7000-8000-000000000001'), new LookupId('abcdefghijkl'), new OwnerRef('user', 'a'), 'CI', new Grants([]), Reach::everything(), $now, $now->modify('+30 days'), null, 'account');
    }
}
