<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Infrastructure\Security;

use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * FrankenPHP worker mode with the kernel kept alive and not reset (REQ-RUNTIME-002): Symfony clears the security token
 * storage only through the services resetter, so a token-authenticated request would leave its user in the storage for
 * the next request of the same worker (a stateless firewall has no context listener to replace it, and a request
 * without an `Authorization` header never reaches the authenticator). This listener removes a token the bundle's
 * authenticator created when the main request finishes, and again before the next main request is authenticated.
 *
 * It only ever removes the bundle's own tokens (recognised by {@see ApiTokenFirewallAuthenticator::TOKEN_PRINCIPAL}),
 * and it uses the untracked token storage, so it never marks a session as used.
 */
final readonly class ApiTokenTokenStorageResetListener
{
    /** SecurityBundle's token storage without session usage tracking. */
    public const TOKEN_STORAGE = 'security.untracked_token_storage';

    /** Runs before the firewall (priority 8). */
    public const REQUEST_PRIORITY = 512;

    /** Runs after everything else of the request. */
    public const FINISH_PRIORITY = -1024;

    /**
     * Wires the listener.
     */
    public function __construct(private TokenStorageInterface $tokenStorage)
    {
    }

    /**
     * Before the main request is authenticated.
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->forget();
        }
    }

    /**
     * When the main request finishes (after the response, also after an exception).
     */
    public function onFinishRequest(FinishRequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->forget();
        }
    }

    private function forget(): void
    {
        $token = $this->tokenStorage->getToken();
        if ($token instanceof TokenInterface && $token->hasAttribute(ApiTokenFirewallAuthenticator::TOKEN_PRINCIPAL)) {
            $this->tokenStorage->setToken(null);
        }
    }
}
