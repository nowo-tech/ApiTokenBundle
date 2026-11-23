<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Unit\Infrastructure\Security;

use Nowo\ApiTokenBundle\Infrastructure\Security\ApiTokenFirewallAuthenticator;
use Nowo\ApiTokenBundle\Infrastructure\Security\ApiTokenTokenStorageResetListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

/**
 * Worker mode, kernel reset false: the bundle's own security token never outlives its main request.
 */
final class TokenStorageResetListenerTest extends TestCase
{
    public function testOnlyTheBundlesTokenIsForgottenAndOnlyAtMainRequestBoundaries(): void
    {
        $storage  = new TokenStorage();
        $listener = new ApiTokenTokenStorageResetListener($storage);
        $kernel   = $this->createStub(HttpKernelInterface::class);
        $request  = Request::create('/');
        $ours     = new PostAuthenticationToken(new InMemoryUser('alice', null), 'api', []);
        $ours->setAttribute(ApiTokenFirewallAuthenticator::TOKEN_PRINCIPAL, 'x');
        $theirs = new PostAuthenticationToken(new InMemoryUser('bob', null), 'main', []);

        $storage->setToken($ours);
        $listener->onFinishRequest(new FinishRequestEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST));
        $listener->onKernelRequest(new RequestEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST));
        self::assertSame($ours, $storage->getToken(), 'a sub-request keeps the main request\'s user');
        $listener->onFinishRequest(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        self::assertNull($storage->getToken());

        $storage->setToken($ours);
        $listener->onKernelRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        self::assertNull($storage->getToken(), 'also before the next main request');

        $storage->setToken($theirs);
        $listener->onKernelRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $listener->onFinishRequest(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        self::assertSame($theirs, $storage->getToken(), 'another authenticator\'s token is never touched');

        $storage->setToken(null);
        $listener->onFinishRequest(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        self::assertNull($storage->getToken());
    }
}
