<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Integration\Database;

use LogicException;
use Nowo\ApiTokenBundle\Application\Token\TokenCodec;
use Nowo\ApiTokenBundle\Contract\ApiTokenIssuerInterface;
use Nowo\ApiTokenBundle\Contract\ApiTokenRevokerInterface;
use Nowo\ApiTokenBundle\Contract\AuthFailure;
use Nowo\ApiTokenBundle\Contract\Event\ApiTokenAuthFailedEvent;
use Nowo\ApiTokenBundle\Contract\Grants;
use Nowo\ApiTokenBundle\Contract\IssuedToken;
use Nowo\ApiTokenBundle\Contract\IssueRequest;
use Nowo\ApiTokenBundle\Contract\Reach;
use Nowo\ApiTokenBundle\Contract\RevocationReason;
use Nowo\ApiTokenBundle\Infrastructure\Security\ApiTokenFirewallAuthenticator;
use Nowo\ApiTokenBundle\Tests\Fixtures\LimitingReachFilter;
use Nowo\ApiTokenBundle\Tests\Fixtures\QueryLog;
use Nowo\ApiTokenBundle\Tests\Fixtures\RecordingListener;
use Nowo\ApiTokenBundle\Tests\Fixtures\Standard\ActiveUserChecker;
use Nowo\ApiTokenBundle\Tests\Integration\Kernel\Host;
use Nowo\ApiTokenBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\ApiTokenBundle\Tests\Integration\Kernel\TestKernel;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

use function array_map;
use function array_unique;
use function array_values;
use function json_decode;
use function preg_match;
use function str_contains;
use function str_repeat;
use function substr;

use const JSON_THROW_ON_ERROR;

/**
 * The S2 firewall suite (contract §6.3, §6.5, §6.7, §11 "Rate limits"), run once per host kind on the configured
 * database. Requests go through `http_kernel` of one kept-alive kernel, with no services resetter (worker mode).
 */
abstract class FirewallFunctionalTestCase extends KernelTestCase
{
    private const IP = '203.0.113.9';

    protected TestKernel $kernel;

    abstract protected function host(): Host;

    public function testAValidTokenSignsInStampsThePrincipalAndConsumesTheTokenBucket(): void
    {
        $this->boot();
        $token = $this->issue('alice', 'CI');

        foreach (['Bearer ', 'bearer ', ''] as $i => $scheme) {
            $response = $this->call($scheme . $token->secret());
            self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
            self::assertSame([
                'owner'     => $this->host()->owner('alice')->key(),
                'token'     => $token->token->id->value,
                'grants'    => ['tasks' => 'read'],
                'reach'     => 'everything',
                'user'      => 'alice',
                'rate'      => 'token',
                'remaining' => 99 - $i,
            ], $this->json($response));
        }
        self::assertSame([], $this->listener()->of(ApiTokenAuthFailedEvent::class));
    }

    public function testEveryFailureAnswersTheSameNamesOnlyExistingTokensAndCountsAgainstTheIp(): void
    {
        $this->boot();
        $wrong   = $this->issue('alice', 'wrong');
        $expired = $this->issue('alice', 'expired', 1);
        $revoked = $this->issue('bob', 'revoked');
        $this->revoker()->revoke($this->host()->owner('bob'), $revoked->token->id, RevocationReason::Owner);
        $this->clock()->modify('+2 days');
        $secret  = $wrong->secret();
        $flipped = substr($secret, 0, 20) . ($secret[20] === 'a' ? 'b' : 'a') . substr($secret, 21);

        $presented = [
            'Bearer ' . $flipped,                     // a typo: the checksum refuses it without a read
            'Bearer ' . $this->withSecretOf($wrong),  // right lookup id and checksum, wrong secret
            'Bearer ' . $expired->secret(),
            'Bearer ' . $revoked->secret(),
            'Bearer nwt_nope',
            'Bearer ',
            'Basic dXNlcjpwYXNz',
        ];
        $bodies = [];
        foreach ($presented as $value) {
            $response = $this->call($value);
            self::assertSame(401, $response->getStatusCode());
            $bodies[] = $this->json($response);
        }
        self::assertSame(array_map(static fn (): array => ['host' => 'no token', 'user' => null, 'rate' => null, 'accepted' => null], $presented), $bodies, 'pass: the host answers, with no user');

        $events = $this->listener()->of(ApiTokenAuthFailedEvent::class);
        self::assertSame(
            [AuthFailure::Malformed, AuthFailure::WrongSecret, AuthFailure::Expired, AuthFailure::Unknown, AuthFailure::Malformed, AuthFailure::Missing, AuthFailure::Malformed],
            array_map(static fn (ApiTokenAuthFailedEvent $event): AuthFailure => $event->failure, $events),
        );
        self::assertSame(
            [null, $wrong->token->lookupId->value, $expired->token->lookupId->value, null, null, null, null],
            array_map(static fn (ApiTokenAuthFailedEvent $event): ?string => $event->lookupId?->value, $events),
        );
        foreach ($events as $event) {
            self::assertSame(1, preg_match('/^[0-9a-f]{16}$/D', $event->clientIpHash));
            self::assertFalse(str_contains($event->clientIpHash, '203'));
            self::assertSame('api_whoami', $event->path);
        }
        self::assertSame($events[0]->clientIpHash, $events[6]->clientIpHash);
    }

    public function testUnauthorizedModeAnswersTokenZeroForEveryKindOfFailure(): void
    {
        $this->boot(['firewall' => ['on_failure' => 'unauthorized']]);
        $expired = $this->issue('alice', 'expired', 1);
        $this->clock()->modify('+2 days');

        $answers = [];
        foreach (['Bearer ' . $expired->secret(), 'Bearer ' . $this->withSecretOf($expired), 'Bearer nwt_nope', ''] as $value) {
            $response = $this->call($value);
            self::assertSame(401, $response->getStatusCode());
            self::assertSame('Bearer', $response->headers->get('WWW-Authenticate'));
            self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            $answers[] = (string) $response->getContent();
        }
        self::assertSame(['{"err":"Invalid token","ECODE":"TOKEN_000"}'], array_values(array_unique($answers)));
    }

    public function testTheFailedIpLimitRefusesBeforeAnyParsingOrRead(): void
    {
        $this->boot(['firewall' => ['on_failure' => 'unauthorized'], 'rate_limit' => ['failed_per_ip' => ['limit' => 3, 'interval' => '15 minutes']]]);
        $valid = $this->issue('alice', 'valid');
        for ($i = 0; $i < 3; ++$i) {
            self::assertSame(401, $this->call('Bearer ' . $this->withSecretOf($valid))->getStatusCode());
        }
        $this->queries()->queries = [];

        $response = $this->call('Bearer ' . $valid->secret());

        self::assertSame(429, $response->getStatusCode(), 'even a valid token from a blocked IP');
        self::assertSame('{"err":"Too many requests","ECODE":"TOKEN_001"}', (string) $response->getContent());
        self::assertGreaterThanOrEqual(1, (int) $response->headers->get('Retry-After'));
        self::assertSame([], $this->tokenQueries(), 'no token read for a blocked IP');
        self::assertSame(200, $this->call('Bearer ' . $valid->secret(), ip: '198.51.100.7')->getStatusCode(), 'another IP is not blocked');
    }

    public function testOneBucketPerTokenAndAWriteSubBudget(): void
    {
        $this->boot(['rate_limit' => ['per_token' => ['limit' => 3, 'interval' => '1 minute'], 'writes' => ['limit' => 1, 'interval' => '1 minute'], 'count_rest_writes' => true]]);
        $first  = $this->issue('alice', 'first');
        $second = $this->issue('alice', 'second');

        self::assertSame(200, $this->call('Bearer ' . $first->secret(), 'POST')->getStatusCode());
        $refusedWrite = $this->call('Bearer ' . $first->secret(), 'POST');
        self::assertSame(429, $refusedWrite->getStatusCode());
        self::assertSame(['host' => 'no token', 'user' => null, 'rate' => 'write', 'accepted' => false], $this->json($refusedWrite));
        $read = $this->call('Bearer ' . $first->secret());
        self::assertSame(200, $read->getStatusCode(), 'a read still has budget');
        self::assertSame(0, $this->json($read)['remaining']);
        $refused = $this->call('Bearer ' . $first->secret());
        self::assertSame(['host' => 'no token', 'user' => null, 'rate' => 'token', 'accepted' => false], $this->json($refused));
        self::assertSame(200, $this->call('Bearer ' . $second->secret())->getStatusCode(), 'each token has its own bucket');
        self::assertSame([], $this->listener()->of(ApiTokenAuthFailedEvent::class), 'a rate limit refusal is not a token failure');
    }

    public function testAnInactiveOwnerIsRefusedAndNamed(): void
    {
        $this->boot();
        $token = $this->issue('alice', 'CI');
        $this->host()->deactivate($this->kernel, 'alice');

        self::assertSame(401, $this->call('Bearer ' . $token->secret())->getStatusCode());
        $events = $this->listener()->of(ApiTokenAuthFailedEvent::class);
        self::assertCount(1, $events);
        self::assertSame(AuthFailure::OwnerInactive, $events[0]->failure);
        self::assertSame($token->token->lookupId->value, $events[0]->lookupId?->value);
    }

    public function testTheReachFilterChainNarrowsThePrincipal(): void
    {
        $this->boot();
        $narrow = $this->issue('alice', 'narrow');
        $wide   = $this->issue('alice', 'wide');

        self::assertSame(1, $this->json($this->call('Bearer ' . $narrow->secret()))['reach']);
        self::assertSame('everything', $this->json($this->call('Bearer ' . $wide->secret()))['reach']);
    }

    public function testTheTokenUserNeverOutlivesItsRequestInAKeptAliveKernel(): void
    {
        $this->boot();
        $token = $this->issue('alice', 'CI');

        self::assertSame('alice', $this->json($this->call('Bearer ' . $token->secret()))['user']);
        self::assertSame(['host' => 'no token', 'user' => null, 'rate' => null, 'accepted' => null], $this->json($this->call(null)), 'no header on the API firewall');
        self::assertSame('alice', $this->json($this->call('Bearer ' . $token->secret()))['user']);
        self::assertSame(['host' => 'no token', 'user' => null, 'rate' => null, 'accepted' => null], $this->json($this->call(null, path: '/open/whoami')), 'a path outside the firewall');
    }

    public function testAStatefulFirewallWithTheAuthenticatorDoesNotBuild(): void
    {
        $host = $this->host();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('is not stateless');
        $this->bootKernel($host->bundleConfig(), Host::databaseUrl(), $host->configure($this->firewall(false)), whoamiRoute: true);
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function boot(array $config = []): void
    {
        $host      = $this->host();
        $base      = $host->configure($this->firewall(true));
        $configure = static function (ContainerConfigurator $container, string $url) use ($base): void {
            $base($container, $url);
            $container->services()->set(LimitingReachFilter::class)->tag('nowo_api_token.reach_filter');
        };
        $this->kernel = $this->bootKernel($host->bundleConfig($config), Host::databaseUrl(), $configure, whoamiRoute: true);
        $host->resetSchema($this->kernel);
        $host->createUser($this->kernel, 'alice');
        $host->createUser($this->kernel, 'bob');
        $this->listener()->clear();
    }

    /**
     * @return array<string, mixed>
     */
    private function firewall(bool $stateless): array
    {
        $firewall = ['pattern' => '^/api', 'stateless' => $stateless, 'custom_authenticators' => [ApiTokenFirewallAuthenticator::class]];

        return $this->host()->kind === Host::STANDARD ? $firewall + ['provider' => 'users', 'user_checker' => ActiveUserChecker::class] : $firewall;
    }

    private function issue(string $owner, string $name, int $days = 30): IssuedToken
    {
        $issuer = $this->service(ApiTokenIssuerInterface::class);
        self::assertInstanceOf(ApiTokenIssuerInterface::class, $issuer);

        return $issuer->issue(new IssueRequest($this->host()->owner($owner), $name, new Grants(['tasks' => 'read']), Reach::everything(), $days));
    }

    /**
     * The token's lookup id with another (well-formed, checksummed) secret.
     */
    private function withSecretOf(IssuedToken $token): string
    {
        $body = 'nwt_' . $token->token->lookupId->value . '_' . str_repeat('Q', 43);

        return $body . TokenCodec::checksum($body);
    }

    private function call(?string $authorization, string $method = 'GET', string $ip = self::IP, string $path = '/api/whoami'): Response
    {
        $http = $this->kernel->getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $http);
        self::assertInstanceOf(TerminableInterface::class, $http);
        $server = ['REMOTE_ADDR' => $ip];
        if ($authorization !== null) {
            $server['HTTP_AUTHORIZATION'] = $authorization;
        }
        $request  = Request::create($path, $method, server: $server);
        $response = $http->handle($request, HttpKernelInterface::MAIN_REQUEST, false);
        $http->terminate($request, $response);

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        $body = json_decode((string) $response->getContent(), true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        /* @var array<string, mixed> $body */
        return $body;
    }

    /**
     * @return list<string>
     */
    private function tokenQueries(): array
    {
        $found = [];
        foreach ($this->queries()->queries as $sql) {
            if (str_contains($sql, $this->host()->tablePrefix() . 'api_token')) {
                $found[] = $sql;
            }
        }

        return $found;
    }

    private function service(string $id): object
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);
        $service = $container->get($id);
        self::assertIsObject($service);

        return $service;
    }

    private function revoker(): ApiTokenRevokerInterface
    {
        $revoker = $this->service(ApiTokenRevokerInterface::class);
        self::assertInstanceOf(ApiTokenRevokerInterface::class, $revoker);

        return $revoker;
    }

    private function clock(): MockClock
    {
        $clock = $this->service('clock');
        self::assertInstanceOf(MockClock::class, $clock);

        return $clock;
    }

    private function listener(): RecordingListener
    {
        $listener = $this->service(RecordingListener::class);
        self::assertInstanceOf(RecordingListener::class, $listener);

        return $listener;
    }

    private function queries(): QueryLog
    {
        $log = $this->service(QueryLog::class);
        self::assertInstanceOf(QueryLog::class, $log);

        return $log;
    }
}
