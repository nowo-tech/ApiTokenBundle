<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Unit\Infrastructure\Security;

use DateTimeImmutable;
use Nowo\ApiTokenBundle\Application\Token\TokenCodec;
use Nowo\ApiTokenBundle\Contract\ApiTokenAuthenticatorInterface;
use Nowo\ApiTokenBundle\Contract\ApiTokenPrincipal;
use Nowo\ApiTokenBundle\Contract\AuthFailure;
use Nowo\ApiTokenBundle\Contract\Event\ApiTokenAuthFailedEvent;
use Nowo\ApiTokenBundle\Contract\Grants;
use Nowo\ApiTokenBundle\Contract\OwnerRef;
use Nowo\ApiTokenBundle\Contract\RateLimitScope;
use Nowo\ApiTokenBundle\Contract\RateLimitState;
use Nowo\ApiTokenBundle\Contract\Reach;
use Nowo\ApiTokenBundle\Contract\TokenId;
use Nowo\ApiTokenBundle\Infrastructure\Security\ApiTokenAuthenticationException;
use Nowo\ApiTokenBundle\Infrastructure\Security\ApiTokenContext;
use Nowo\ApiTokenBundle\Infrastructure\Security\ApiTokenFirewallAuthenticator;
use Nowo\ApiTokenBundle\Infrastructure\Security\ApiTokenRateLimitedException;
use Nowo\ApiTokenBundle\Infrastructure\Security\ClientIpHasher;
use Nowo\ApiTokenBundle\Tests\Fixtures\FakeRateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use SensitiveParameter;
use stdClass;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Exception\LockedException;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function in_array;
use function str_repeat;

/**
 * The firewall authenticator (contract §6.3): credential source, IP peek before parsing, identical refusals, the
 * failure event, the token budgets and the request stamp.
 */
final class FirewallAuthenticatorTest extends TestCase
{
    private const IP = '203.0.113.9';

    private FakeRateLimiter $limiter;

    /** @var list<object> */
    private array $events = [];

    /** @var list<string> */
    private array $presented = [];

    private ApiTokenPrincipal|AuthFailure $answer = AuthFailure::Unknown;

    private TokenCodec $codec;

    protected function setUp(): void
    {
        $this->limiter   = new FakeRateLimiter();
        $this->events    = [];
        $this->presented = [];
        $this->codec     = new TokenCodec('nwt_');
    }

    public function testSupportsAnyAuthorizationHeaderAndDropsEarlierStamps(): void
    {
        $request = $this->request(null);
        $request->attributes->set(ApiTokenContext::PRINCIPAL, $this->principal(new InMemoryUser('alice', null)));
        $request->attributes->set(ApiTokenContext::RATE_LIMIT, new RateLimitState(RateLimitScope::Token, true, 1, 1, new DateTimeImmutable()));

        self::assertFalse($this->firewall()->supports($request));
        self::assertNull(ApiTokenContext::current($request), 'a value set before the firewall is never trusted');
        self::assertNull(ApiTokenContext::rateLimit($request));
        self::assertTrue($this->firewall()->supports($this->request('Basic x')));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function credentials(): iterable
    {
        yield 'Bearer' => ['Bearer nwt_abc', 'nwt_abc'];
        yield 'lower case' => ['bearer nwt_abc', 'nwt_abc'];
        yield 'bare' => ['nwt_abc', 'nwt_abc'];
        yield 'empty Bearer' => ['Bearer ', ''];
        yield 'another scheme' => ['Basic dXNlcg==', 'Basic dXNlcg=='];
    }

    #[DataProvider('credentials')]
    public function testOnlyTheAuthorizationHeaderIsRead(string $header, string $presented): void
    {
        $request = Request::create('/api/x?token=nwt_query', 'GET', ['token' => 'nwt_body'], ['token' => 'nwt_cookie'], [], ['HTTP_AUTHORIZATION' => $header, 'REMOTE_ADDR' => self::IP]);

        try {
            $this->firewall()->authenticate($request);
            self::fail('refused');
        } catch (ApiTokenAuthenticationException) {
        }

        self::assertSame([$presented], $this->presented);
    }

    public function testABlockedIpIsRefusedBeforeTheTokenIsLookedAt(): void
    {
        $this->limiter->refuse = [RateLimitScope::FailedIp];

        try {
            $this->firewall()->authenticate($this->request('Bearer nwt_x'));
            self::fail('refused');
        } catch (ApiTokenRateLimitedException $exception) {
            self::assertSame(RateLimitScope::FailedIp, $exception->state->scope);
            self::assertSame(ApiTokenRateLimitedException::MESSAGE, $exception->getMessageKey());
        }
        self::assertSame([], $this->presented);
        self::assertSame(['peek:' . self::IP], $this->limiter->calls);
    }

    /**
     * @return iterable<string, array{AuthFailure, bool}>
     */
    public static function failures(): iterable
    {
        foreach (AuthFailure::cases() as $failure) {
            yield $failure->value => [$failure, in_array($failure, [AuthFailure::WrongSecret, AuthFailure::Expired, AuthFailure::OwnerInactive], true)];
        }
    }

    #[DataProvider('failures')]
    public function testEveryFailureIsCountedReportedAndAnsweredTheSame(AuthFailure $failure, bool $named): void
    {
        $token        = $this->codec->generate();
        $this->answer = $failure;
        $request      = $this->request('Bearer ' . $token->token());
        $firewall     = $this->firewall(ApiTokenFirewallAuthenticator::ON_FAILURE_UNAUTHORIZED);

        try {
            $firewall->authenticate($request);
            self::fail('refused');
        } catch (ApiTokenAuthenticationException $exception) {
            self::assertSame($failure, $exception->failure);
            self::assertSame(ApiTokenAuthenticationException::MESSAGE, $exception->getMessageKey());
            self::assertStringNotContainsString($token->token(), $exception->getMessage());
            $response = $firewall->onAuthenticationFailure($request, $exception);
        }

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('{"err":"Invalid token","ECODE":"TOKEN_000"}', $response->getContent());
        self::assertSame('Bearer', $response->headers->get('WWW-Authenticate'));
        self::assertSame(['peek:' . self::IP, 'failed:' . self::IP], $this->limiter->calls);
        self::assertCount(1, $this->events);
        $event = $this->events[0];
        self::assertInstanceOf(ApiTokenAuthFailedEvent::class, $event);
        self::assertSame($failure, $event->failure);
        self::assertSame($named ? $token->lookupId->value : null, $event->lookupId?->value);
        self::assertSame((new ClientIpHasher('s'))->short(self::IP), $event->clientIpHash);
        self::assertSame('api_x', $event->path);
    }

    public function testPassLetsTheRequestThroughWithNoStamp(): void
    {
        $request = $this->request('Bearer x');
        $request->attributes->remove('_route');

        self::assertNull($this->firewall()->onAuthenticationFailure($request, new ApiTokenAuthenticationException(AuthFailure::Malformed)));
        self::assertNull(ApiTokenContext::current($request));
        self::assertInstanceOf(ApiTokenAuthFailedEvent::class, $this->events[0]);
        self::assertSame('/api/x', $this->events[0]->path, 'no route: the path, never the query');
    }

    public function testARefusalBySymfonyAfterTheTokenCheckIsAnInactiveOwner(): void
    {
        $token = $this->codec->generate();

        self::assertNull($this->firewall()->onAuthenticationFailure($this->request($token->token()), new LockedException()));
        self::assertInstanceOf(ApiTokenAuthFailedEvent::class, $this->events[0]);
        self::assertSame(AuthFailure::OwnerInactive, $this->events[0]->failure);
        self::assertSame($token->lookupId->value, $this->events[0]->lookupId?->value);
    }

    public function testAnOwnerThatIsNotASymfonyUserCannotSignIn(): void
    {
        $token        = $this->codec->generate();
        $this->answer = $this->principal(new stdClass());

        try {
            $this->firewall()->authenticate($this->request('Bearer ' . $token->token()));
            self::fail('refused');
        } catch (ApiTokenAuthenticationException $exception) {
            self::assertSame(AuthFailure::OwnerInactive, $exception->failure);
            self::assertSame($token->lookupId->value, $exception->lookupId?->value);
        }
    }

    public function testASuccessConsumesTheTokenBucketAndStampsOnlyAfterSymfonyAccepts(): void
    {
        $user         = new InMemoryUser('alice', null, ['ROLE_API']);
        $this->answer = $this->principal($user);
        $request      = $this->request('Bearer nwt_x');
        $firewall     = $this->firewall();

        $passport = $firewall->authenticate($request);
        self::assertSame($user, $passport->getUser());
        self::assertNull(ApiTokenContext::current($request), 'nothing is stamped before the passport is accepted');
        $token = $firewall->createToken($passport, 'api');
        self::assertSame(['ROLE_API'], $token->getRoleNames());
        self::assertNull($firewall->onAuthenticationSuccess($request, $token, 'api'));

        self::assertSame($this->answer, ApiTokenContext::current($request));
        self::assertSame(RateLimitScope::Token, ApiTokenContext::rateLimit($request)?->scope);
        self::assertSame(['peek:' . self::IP, 'consume:' . $this->principal($user)->tokenId->value], $this->limiter->calls);
        self::assertSame([], $this->events);
    }

    public function testASuccessWithAForeignTokenStampsNothing(): void
    {
        $request = $this->request('Bearer nwt_x');

        self::assertNull($this->firewall()->onAuthenticationSuccess($request, new NullToken(), 'api'));
        self::assertNull(ApiTokenContext::current($request));
        self::assertNull(ApiTokenContext::rateLimit($request));
    }

    public function testUnsafeMethodsConsumeTheWriteBudgetWhenCounted(): void
    {
        $this->answer = $this->principal(new InMemoryUser('alice', null));
        $id           = $this->principal(new InMemoryUser('alice', null))->tokenId->value;

        $this->firewall(countRestWrites: true)->authenticate($this->request('Bearer nwt_x'));
        $this->firewall(countRestWrites: true)->authenticate($this->request('Bearer nwt_x', 'POST'));
        $this->firewall()->authenticate($this->request('Bearer nwt_x', 'DELETE'));

        self::assertSame([
            'peek:' . self::IP, 'consume:' . $id,
            'peek:' . self::IP, 'consume:' . $id, 'write:' . $id,
            'peek:' . self::IP, 'consume:' . $id,
        ], $this->limiter->calls);
    }

    /**
     * @return iterable<string, array{list<RateLimitScope>, string, RateLimitScope}>
     */
    public static function budgets(): iterable
    {
        yield 'requests' => [[RateLimitScope::Token], 'GET', RateLimitScope::Token];
        yield 'writes' => [[RateLimitScope::Write], 'POST', RateLimitScope::Write];
    }

    /**
     * @param list<RateLimitScope> $refuse
     */
    #[DataProvider('budgets')]
    public function testAUsedUpBudgetIsATooManyRequestsAnswerNotATokenFailure(array $refuse, string $method, RateLimitScope $scope): void
    {
        $this->answer          = $this->principal(new InMemoryUser('alice', null));
        $this->limiter->refuse = $refuse;
        $request               = $this->request('Bearer nwt_x', $method);
        $unauthorized          = $this->firewall(ApiTokenFirewallAuthenticator::ON_FAILURE_UNAUTHORIZED, true);

        try {
            $unauthorized->authenticate($request);
            self::fail('refused');
        } catch (ApiTokenRateLimitedException $exception) {
            $response = $unauthorized->onAuthenticationFailure($request, $exception);
            self::assertInstanceOf(Response::class, $response);
            self::assertSame(429, $response->getStatusCode());
            self::assertSame('{"err":"Too many requests","ECODE":"TOKEN_001"}', $response->getContent());
            self::assertSame('42', $response->headers->get('Retry-After'));
            self::assertSame($scope, ApiTokenContext::rateLimit($request)?->scope);
            self::assertNull($this->firewall()->onAuthenticationFailure($request, $exception), 'pass: the API answers');
            self::assertFalse(ApiTokenContext::rateLimit($request)->accepted);
        }
        self::assertSame([], $this->events);
        self::assertNotContains('failed:' . self::IP, $this->limiter->calls);
    }

    public function testTheEntryPointAnswersTokenZero(): void
    {
        $response = $this->firewall()->start($this->request(null));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('{"err":"Invalid token","ECODE":"TOKEN_000"}', $response->getContent());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testADefaultClockIsTheSystemClock(): void
    {
        $firewall = new ApiTokenFirewallAuthenticator($this->authenticator(), $this->limiter, $this->codec, $this->dispatcher(), new ClientIpHasher('s'));
        $state    = new RateLimitState(RateLimitScope::Token, false, 1, 0, new DateTimeImmutable('+1 hour'));

        $response = $firewall->onAuthenticationFailure($this->request('x'), new ApiTokenRateLimitedException($state));

        self::assertNull($response);
    }

    private function firewall(string $onFailure = ApiTokenFirewallAuthenticator::ON_FAILURE_PASS, bool $countRestWrites = false): ApiTokenFirewallAuthenticator
    {
        return new ApiTokenFirewallAuthenticator($this->authenticator(), $this->limiter, $this->codec, $this->dispatcher(), new ClientIpHasher('s'), $onFailure, $countRestWrites, new MockClock('2026-11-12 10:00:00'));
    }

    private function authenticator(): ApiTokenAuthenticatorInterface
    {
        $test = $this;

        return new class($test) implements ApiTokenAuthenticatorInterface {
            public function __construct(private readonly FirewallAuthenticatorTest $test)
            {
            }

            public function authenticate(#[SensitiveParameter] string $presented): ApiTokenPrincipal|AuthFailure
            {
                return $this->test->answer($presented);
            }
        };
    }

    public function answer(string $presented): ApiTokenPrincipal|AuthFailure
    {
        $this->presented[] = $presented;

        return $this->answer;
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $test = $this;

        return new class($test) implements EventDispatcherInterface {
            public function __construct(private readonly FirewallAuthenticatorTest $test)
            {
            }

            public function dispatch(object $event): object
            {
                $this->test->record($event);

                return $event;
            }
        };
    }

    public function record(object $event): void
    {
        $this->events[] = $event;
    }

    private function request(?string $authorization, string $method = 'GET'): Request
    {
        $server = ['REMOTE_ADDR' => self::IP];
        if ($authorization !== null) {
            $server['HTTP_AUTHORIZATION'] = $authorization;
        }
        $request = Request::create('/api/x?secret=' . str_repeat('q', 4), $method, server: $server);
        $request->attributes->set('_route', 'api_x');

        return $request;
    }

    private function principal(object $user): ApiTokenPrincipal
    {
        return new ApiTokenPrincipal(new TokenId('01935f7e-0000-7000-8000-000000000001'), 'CI', new OwnerRef('user', 'alice'), $user, new Grants(['tasks' => 'read']), Reach::everything(), new DateTimeImmutable('2027-01-01'));
    }
}
