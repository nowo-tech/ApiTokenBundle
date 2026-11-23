<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Integration\Worker;

use Doctrine\DBAL\Types\Types;
use LogicException;
use Nowo\ApiTokenBundle\Contract\ApiTokenIssuerInterface;
use Nowo\ApiTokenBundle\Contract\ApiTokenRevokerInterface;
use Nowo\ApiTokenBundle\Contract\Grants;
use Nowo\ApiTokenBundle\Contract\IssuedToken;
use Nowo\ApiTokenBundle\Contract\IssueRequest;
use Nowo\ApiTokenBundle\Contract\Reach;
use Nowo\ApiTokenBundle\Contract\RevocationReason;
use Nowo\ApiTokenBundle\Infrastructure\Persistence\AfterCommitQueue;
use Nowo\ApiTokenBundle\Infrastructure\Security\ApiTokenFirewallAuthenticator;
use Nowo\ApiTokenBundle\Tests\Fixtures\ForeignSchemeAuthenticator;
use Nowo\ApiTokenBundle\Tests\Fixtures\Standard\ActiveUserChecker;
use Nowo\ApiTokenBundle\Tests\Fixtures\TokenReplacingListener;
use Nowo\ApiTokenBundle\Tests\Integration\Kernel\Host;
use Nowo\ApiTokenBundle\Tests\Integration\Kernel\KernelTestCase;
use stdClass;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Security\Http\Event\AuthenticationTokenCreatedEvent;
use Symfony\Contracts\Service\ResetInterface;

use function gc_collect_cycles;
use function json_decode;
use function memory_get_usage;
use function substr;

use const JSON_THROW_ON_ERROR;

/**
 * FrankenPHP worker mode with the kernel kept alive and NOT reset (`FRANKENPHP_RESET_KERNEL=false`, contract §10): one
 * booted kernel serves 2,000 requests through `http_kernel` with no services resetter. Two owners and three tokens
 * alternate with a wrong secret and an expired token; one token is revoked mid-run. Each request must see only its own
 * principal, a refused one must carry no principal, nothing may stay held, and memory must stay flat (≤ 1 MiB).
 */
final class WorkerRequestLoopTest extends KernelTestCase
{
    private const REQUESTS = 2000;

    public function testOneKeptAliveKernelServesManyTokensWithoutLeakingStateOrMemory(): void
    {
        $host   = Host::standard();
        $kernel = $this->bootKernel($host->bundleConfig(), Host::databaseUrl(), $host->configure(), true);
        $host->resetSchema($kernel);
        $host->createUser($kernel, 'alice');
        $host->createUser($kernel, 'bob');
        $container = $kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);
        $issuer  = $container->get(ApiTokenIssuerInterface::class);
        $revoker = $container->get(ApiTokenRevokerInterface::class);
        $queue   = $container->get(AfterCommitQueue::class);
        $http    = $kernel->getContainer()->get('http_kernel');
        self::assertInstanceOf(ApiTokenIssuerInterface::class, $issuer);
        self::assertInstanceOf(ApiTokenRevokerInterface::class, $revoker);
        self::assertInstanceOf(AfterCommitQueue::class, $queue);
        self::assertInstanceOf(HttpKernelInterface::class, $http);
        self::assertInstanceOf(TerminableInterface::class, $http);

        $issue = static fn (string $owner, string $name, string $level, int $days = 30): IssuedToken => $issuer->issue(new IssueRequest($host->owner($owner), $name, new Grants(['tasks' => $level]), Reach::everything(), $days));
        $a1    = $issue('alice', 'a1', 'read');
        $a2    = $issue('alice', 'a2', 'full');
        $b1    = $issue('bob', 'b1', 'tasks');
        $short = $issue('bob', 'short', 'read', 1);
        $clock = $container->get('clock');
        self::assertInstanceOf(MockClock::class, $clock);
        $clock->modify('+1 day');
        $wrong = substr($a1->secret(), 0, 20) . ($a1->secret()[20] === 'a' ? 'b' : 'a') . substr($a1->secret(), 21);

        $cases = [
            ['token' => $a1->secret(), 'owner' => 'user:alice', 'id' => $a1->token->id->value, 'grants' => ['tasks' => 'read']],
            ['token' => $b1->secret(), 'owner' => 'user:bob', 'id' => $b1->token->id->value, 'grants' => ['tasks' => 'tasks']],
            ['token' => $wrong, 'failure' => 'malformed'],
            ['token' => $a2->secret(), 'owner' => 'user:alice', 'id' => $a2->token->id->value, 'grants' => ['tasks' => 'full']],
            ['token' => $short->secret(), 'failure' => 'expired'],
            ['token' => '', 'failure' => 'missing'],
        ];

        $baseline = 0;
        for ($i = 0; $i < self::REQUESTS; ++$i) {
            if ($i === 1000) {
                self::assertTrue($revoker->revoke($host->owner('alice'), $a2->token->id, RevocationReason::Owner));
                $cases[3] = ['token' => $a2->secret(), 'failure' => 'unknown'];
            }
            $case  = $cases[$i % 6];
            $token = $case['token'];
            self::assertIsString($token);
            $request  = Request::create('/auth', 'GET', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'REMOTE_ADDR' => '203.0.113.9']);
            $response = $http->handle($request, HttpKernelInterface::MAIN_REQUEST, false);
            $http->terminate($request, $response);
            $body = json_decode((string) $response->getContent(), true, 8, JSON_THROW_ON_ERROR);

            if (isset($case['failure'])) {
                self::assertSame(401, $response->getStatusCode(), 'request ' . $i);
                self::assertSame(['failure' => $case['failure']], $body, 'request ' . $i . ' carries no principal');
            } else {
                self::assertSame(200, $response->getStatusCode(), 'request ' . $i);
                self::assertSame(['owner' => $case['owner'], 'token' => $case['id'], 'grants' => $case['grants']], $body, 'request ' . $i . ' sees only its own principal');
            }
            self::assertSame(0, $queue->heldCount());

            if ($i === 100) {
                gc_collect_cycles();
                $baseline = memory_get_usage();
            }
            if ($i % 100 === 0) {
                $clock->modify('+61 seconds');
            }
        }
        gc_collect_cycles();

        self::assertLessThan(1024 * 1024, memory_get_usage() - $baseline, 'memory stays flat over ' . self::REQUESTS . ' requests');
    }

    /**
     * The same loop through the S2 firewall (`custom_authenticators` on a stateless `^/api` firewall): each request sees
     * only its own principal and its own security user; a refused request, a request without a header and a request
     * outside the firewall carry neither (the token storage is not reset by Symfony in this mode); memory stays flat.
     */
    public function testTheFirewallNeverCarriesAUserOrPrincipalIntoTheNextRequest(): void
    {
        $host     = Host::standard();
        $firewall = ['pattern' => '^/api', 'stateless' => true, 'provider' => 'users', 'user_checker' => ActiveUserChecker::class, 'custom_authenticators' => [ApiTokenFirewallAuthenticator::class]];
        $limits   = ['rate_limit' => ['per_token' => ['limit' => 100000, 'interval' => '1 minute'], 'failed_per_ip' => ['limit' => 100000, 'interval' => '1 minute']]];
        $kernel   = $this->bootKernel($host->bundleConfig($limits), Host::databaseUrl(), $host->configure($firewall), whoamiRoute: true);
        $host->resetSchema($kernel);
        $host->createUser($kernel, 'alice');
        $host->createUser($kernel, 'bob');
        $container = $kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);
        $issuer  = $container->get(ApiTokenIssuerInterface::class);
        $revoker = $container->get(ApiTokenRevokerInterface::class);
        $http    = $kernel->getContainer()->get('http_kernel');
        self::assertInstanceOf(ApiTokenIssuerInterface::class, $issuer);
        self::assertInstanceOf(ApiTokenRevokerInterface::class, $revoker);
        self::assertInstanceOf(HttpKernelInterface::class, $http);
        self::assertInstanceOf(TerminableInterface::class, $http);
        $issue = static fn (string $owner, string $name): IssuedToken => $issuer->issue(new IssueRequest($host->owner($owner), $name, new Grants(['tasks' => 'read']), Reach::everything(), 30));
        $a1    = $issue('alice', 'a1');
        $a2    = $issue('alice', 'a2');
        $b1    = $issue('bob', 'b1');
        $wrong = substr($b1->secret(), 0, 20) . ($b1->secret()[20] === 'a' ? 'b' : 'a') . substr($b1->secret(), 21);
        $none  = ['host' => 'no token', 'user' => null, 'rate' => null, 'accepted' => null];
        $cases = [
            ['/api/whoami', 'Bearer ' . $a1->secret(), $a1->token->id->value, 'alice'],
            ['/api/whoami', 'Bearer ' . $wrong, null, null],
            ['/api/whoami', 'Bearer ' . $b1->secret(), $b1->token->id->value, 'bob'],
            ['/api/whoami', null, null, null],
            ['/api/whoami', 'Bearer ' . $a2->secret(), $a2->token->id->value, 'alice'],
            ['/open/whoami', null, null, null],
        ];

        $baseline = 0;
        for ($i = 0; $i < self::REQUESTS; ++$i) {
            if ($i === 1000) {
                self::assertTrue($revoker->revoke($host->owner('alice'), $a2->token->id, RevocationReason::Owner));
                $cases[4] = ['/api/whoami', 'Bearer ' . $a2->secret(), null, null];
            }
            [$path, $authorization, $tokenId, $user] = $cases[$i % 6];
            $server                                  = ['REMOTE_ADDR' => '203.0.113.9'];
            if ($authorization !== null) {
                $server['HTTP_AUTHORIZATION'] = $authorization;
            }
            $request  = Request::create($path, 'GET', server: $server);
            $response = $http->handle($request, HttpKernelInterface::MAIN_REQUEST, false);
            $http->terminate($request, $response);
            $body = json_decode((string) $response->getContent(), true, 8, JSON_THROW_ON_ERROR);
            self::assertIsArray($body);

            if ($tokenId === null) {
                self::assertSame(401, $response->getStatusCode(), 'request ' . $i);
                self::assertSame($none, $body, 'request ' . $i . ' carries no principal and no user');
            } else {
                self::assertSame(200, $response->getStatusCode(), 'request ' . $i);
                self::assertSame($tokenId, $body['token'], 'request ' . $i . ' sees only its own principal');
                self::assertSame($user, $body['user'], 'request ' . $i . ' sees only its own user');
            }

            if ($i === 100) {
                gc_collect_cycles();
                $baseline = memory_get_usage();
            }
        }
        gc_collect_cycles();

        self::assertLessThan(1024 * 1024, memory_get_usage() - $baseline, 'memory stays flat over ' . self::REQUESTS . ' firewall requests');
    }

    /**
     * Audit ZAB-S2-L1/L2/L3 in the worker loop: a token firewall shared with another authenticator, a listener that
     * replaces the bundle's token after its creation (2FA, stateless switch_user) and the host's own stateful `lazy: true`
     * firewall. Requests that never read the storage, requests signed in by the other authenticator and replaced tokens
     * alternate with requests without a header; none of them ever sees an earlier request's user, and the lazy firewall
     * is only ever authenticated by a read.
     */
    public function testALazySharedFirewallWithAReplacedTokenNeverCarriesAUserIntoTheNextRequest(): void
    {
        $host     = Host::standard();
        $firewall = ['pattern' => '^/api', 'stateless' => true, 'provider' => 'users', 'user_checker' => ActiveUserChecker::class, 'custom_authenticators' => [ApiTokenFirewallAuthenticator::class, ForeignSchemeAuthenticator::class]];
        $limits   = ['rate_limit' => ['per_token' => ['limit' => 100000, 'interval' => '1 minute'], 'failed_per_ip' => ['limit' => 3, 'interval' => '1 minute']]];
        $base     = $host->configure($firewall, ['extra_firewalls' => ['main' => ['pattern' => '^/main', 'lazy' => true, 'provider' => 'users', 'custom_authenticators' => [ForeignSchemeAuthenticator::class]]]]);
        $kernel   = $this->bootKernel($host->bundleConfig($limits), Host::databaseUrl(), static function (ContainerConfigurator $container, string $url) use ($base): void {
            $base($container, $url);
            $container->services()->set(ForeignSchemeAuthenticator::class);
            $container->services()->set(TokenReplacingListener::class)->tag('kernel.event_listener', ['event' => AuthenticationTokenCreatedEvent::class, 'method' => 'onTokenCreated']);
        }, whoamiRoute: true);
        $host->resetSchema($kernel);
        $host->createUser($kernel, 'alice');
        $container = $kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);
        $issuer = $container->get(ApiTokenIssuerInterface::class);
        $http   = $kernel->getContainer()->get('http_kernel');
        self::assertInstanceOf(ApiTokenIssuerInterface::class, $issuer);
        self::assertInstanceOf(HttpKernelInterface::class, $http);
        self::assertInstanceOf(TerminableInterface::class, $http);
        $secret = $issuer->issue(new IssueRequest($host->owner('alice'), 'a1', new Grants(['tasks' => 'read']), Reach::everything(), 30))->secret();
        $cases  = [
            ['/api/whoami', 'Bearer ' . $secret, 'alice'],          // replaced token
            ['/api/whoami', null, null],
            ['/api/echo', 'Bearer ' . $secret, false],              // the storage is never read
            ['/open/whoami', null, null],
            ['/main/echo', 'Basic dXNlcjpwYXNz', false],            // lazy, never read: never authenticated
            ['/main/whoami', null, null],
            ['/main/whoami', 'Basic dXNlcjpwYXNz', 'basic-user'],
            ['/api/whoami', 'Basic dXNlcjpwYXNz', 'basic-user'],    // the other authenticator
            ['/api/whoami', null, null],
            ['/api/whoami', 'Bearer eyJhbGciOiJIUzI1NiJ9.e30.c2ln', null],
            ['/open/whoami', null, null],
        ];

        $reads = 0;
        for ($i = 0; $i < self::REQUESTS / 4; ++$i) {
            [$path, $authorization, $user] = $cases[$i % 11];
            if ($user === 'basic-user') {
                ++$reads;
            }
            $server = ['REMOTE_ADDR' => '203.0.113.9'];
            if ($authorization !== null) {
                $server['HTTP_AUTHORIZATION'] = $authorization;
            }
            $request  = Request::create($path, 'GET', server: $server);
            $response = $http->handle($request, HttpKernelInterface::MAIN_REQUEST, false);
            $http->terminate($request, $response);
            $body = json_decode((string) $response->getContent(), true, 8, JSON_THROW_ON_ERROR);
            self::assertIsArray($body);
            if ($user === false) {
                self::assertSame(200, $response->getStatusCode(), 'request ' . $i);
                continue;
            }
            self::assertSame($user, $body['user'], 'request ' . $i . ' sees only its own user');
        }
        $foreign = $container->get(ForeignSchemeAuthenticator::class);
        self::assertInstanceOf(ForeignSchemeAuthenticator::class, $foreign);
        self::assertSame($reads, $foreign->runs, 'only the Basic requests that read the storage ran it');
    }

    /**
     * Audit LW-M2 / REQ-RUNTIME-002: one kernel kept alive; an admin or another worker disables the account with a
     * plain SQL UPDATE; the very next request is refused, and re-enabling it brings the token back.
     */
    public function testADeactivationBySqlIsRefusedOnTheNextRequest(): void
    {
        $host   = Host::standard();
        $kernel = $this->bootKernel($host->bundleConfig(), Host::databaseUrl(), $host->configure(), true);
        $host->resetSchema($kernel);
        $host->createUser($kernel, 'alice');
        $container = $kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);
        $issuer = $container->get(ApiTokenIssuerInterface::class);
        $http   = $kernel->getContainer()->get('http_kernel');
        self::assertInstanceOf(ApiTokenIssuerInterface::class, $issuer);
        self::assertInstanceOf(HttpKernelInterface::class, $http);
        self::assertInstanceOf(TerminableInterface::class, $http);
        $secret     = $issuer->issue(new IssueRequest($host->owner('alice'), 'w', new Grants(['tasks' => 'read']), Reach::everything(), 30))->secret();
        $connection = $host->entityManager($kernel)->getConnection();
        $call       = static function () use ($http, $secret): int {
            $request  = Request::create('/auth', 'GET', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $secret]);
            $response = $http->handle($request, HttpKernelInterface::MAIN_REQUEST, false);
            $http->terminate($request, $response);

            return $response->getStatusCode();
        };

        for ($i = 0; $i < 3; ++$i) {
            self::assertSame(200, $call());
        }
        $connection->update('app_user', ['active' => false], ['id' => 'alice'], ['active' => Types::BOOLEAN]);
        self::assertSame(401, $call(), 'disabled by SQL: refused on the next request');
        self::assertSame(401, $call());
        $connection->update('app_user', ['active' => true], ['id' => 'alice'], ['active' => Types::BOOLEAN]);
        self::assertSame(200, $call());
    }

    public function testHeldAfterCommitWorkIsDroppedAtEveryBoundary(): void
    {
        $host      = Host::standard();
        $kernel    = $this->bootKernel($host->bundleConfig(), Host::databaseUrl(), $host->configure(), true);
        $container = $kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);
        $queue  = $container->get(AfterCommitQueue::class);
        $events = $kernel->getContainer()->get('event_dispatcher');
        $http   = $kernel->getContainer()->get('http_kernel');
        self::assertInstanceOf(AfterCommitQueue::class, $queue);
        self::assertInstanceOf(EventDispatcherInterface::class, $events);
        self::assertInstanceOf(HttpKernelInterface::class, $http);
        $connection = new stdClass();

        $hold = static function () use ($queue, $connection): void {
            $queue->hold($connection, static function (): never {
                throw new LogicException('never runs');
            });
        };

        $hold();
        $events->dispatch(new FinishRequestEvent($kernel, Request::create('/'), HttpKernelInterface::SUB_REQUEST), 'kernel.finish_request');
        self::assertSame(1, $queue->heldCount(), 'a sub-request keeps the main request\'s held work');
        $events->dispatch(new FinishRequestEvent($kernel, Request::create('/'), HttpKernelInterface::MAIN_REQUEST), 'kernel.finish_request');
        self::assertSame(0, $queue->heldCount());

        $hold();
        $events->dispatch(new TerminateEvent($kernel, Request::create('/'), new Response()), 'kernel.terminate');
        self::assertSame(0, $queue->heldCount());

        $hold();
        $events->dispatch(new ConsoleTerminateEvent(new Command('x'), new ArrayInput([]), new NullOutput(), 0), ConsoleEvents::TERMINATE);
        self::assertSame(0, $queue->heldCount());

        $hold();
        $resetter = $kernel->getContainer()->get('services_resetter');
        self::assertInstanceOf(ResetInterface::class, $resetter);
        $resetter->reset();
        self::assertSame(0, $queue->heldCount(), 'kernel.reset');

        $hold();
        $queue->committed(new stdClass());
        self::assertSame(1, $queue->heldCount(), 'another connection\'s commit runs nothing');
        $queue->rolledBack($connection);
        self::assertSame(0, $queue->heldCount());
    }
}
