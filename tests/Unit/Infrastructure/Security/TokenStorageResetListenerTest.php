<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Unit\Infrastructure\Security;

use Nowo\ApiTokenBundle\Infrastructure\Security\ApiTokenContext;
use Nowo\ApiTokenBundle\Infrastructure\Security\ApiTokenTokenStorageResetListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

/**
 * Worker mode, kernel reset false: nothing the bundle's firewall put in the token storage outlives its main request,
 * and the storage is never read (a lazy firewall's initializer never runs), audit ZAB-S2-L2 / L3.
 */
final class TokenStorageResetListenerTest extends TestCase
{
    private const API = 'security.firewall.map.context.api';

    public function testASignedInRequestEmptiesTheStorageWhateverTokenItHolds(): void
    {
        $storage  = new TokenStorage();
        $listener = new ApiTokenTokenStorageResetListener($storage, [self::API]);
        $request  = Request::create('/');
        ApiTokenContext::markSignedIn($request);
        // A token replaced after creation (2FA listener, stateless switch_user): it does not carry the bundle's attributes.
        $replaced = new PostAuthenticationToken(new InMemoryUser('alice', null), 'other', []);

        $storage->setToken($replaced);
        $listener->onFinishRequest($this->finish($request, HttpKernelInterface::SUB_REQUEST));
        self::assertSame($replaced, $storage->getToken(), 'a sub-request keeps the main request\'s user');
        $listener->onFinishRequest($this->finish($request, HttpKernelInterface::MAIN_REQUEST));
        self::assertNull($storage->getToken());
    }

    public function testARequestOnTheTokenFirewallEmptiesTheStorageWithoutRunningALazyInitializer(): void
    {
        $storage  = new TokenStorage();
        $listener = new ApiTokenTokenStorageResetListener($storage, [self::API]);
        $request  = Request::create('/api/x');
        $request->attributes->set('_firewall_context', self::API);
        $ran = false;
        $storage->setInitializer(static function () use (&$ran): void {
            $ran = true;
        });

        $listener->onFinishRequest($this->finish($request, HttpKernelInterface::MAIN_REQUEST));

        self::assertNull($storage->getToken());
        self::assertFalse($ran, 'the pending initializer is dropped, never run');
    }

    public function testAnotherFirewallsStorageIsNeverReadNorTouched(): void
    {
        $storage  = new TokenStorage();
        $listener = new ApiTokenTokenStorageResetListener($storage, [self::API]);
        $theirs   = new PostAuthenticationToken(new InMemoryUser('bob', null), 'main', []);
        $ran      = false;
        $storage->setToken($theirs);
        $storage->setInitializer(static function () use (&$ran): void {
            $ran = true;
        });
        $main = Request::create('/');
        $main->attributes->set('_firewall_context', 'security.firewall.map.context.main');

        $listener->onFinishRequest($this->finish($main, HttpKernelInterface::MAIN_REQUEST));
        $listener->onFinishRequest($this->finish(Request::create('/open'), HttpKernelInterface::MAIN_REQUEST));
        self::assertFalse($ran, 'a lazy firewall is not authenticated after the controller');
        self::assertSame($theirs, $storage->getToken());

        (new ApiTokenTokenStorageResetListener($storage))->onFinishRequest($this->finish($main, HttpKernelInterface::MAIN_REQUEST));
        self::assertSame($theirs, $storage->getToken());
    }

    private function finish(Request $request, int $type): FinishRequestEvent
    {
        return new FinishRequestEvent($this->createStub(HttpKernelInterface::class), $request, $type);
    }
}
