<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Infrastructure\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

use function in_array;

/**
 * FrankenPHP worker mode with the kernel kept alive and not reset (REQ-RUNTIME-002): Symfony clears the security token
 * storage only through the services resetter, so a token-authenticated request would leave its user in the storage for
 * the next request of the same worker (a stateless firewall has no context listener to replace it, and a request
 * without an `Authorization` header never reaches the authenticator). This listener empties the storage at two points.
 *
 * Before any firewall of every main request (`kernel.request`, priority 512), unconditionally: nothing a previous
 * request left may be inherited, even when its `kernel.finish_request` never ran (audit ZAB-R-L1:
 * `framework.handle_all_throwables: false` with an `\Error`, or a host `kernel.finish_request` listener that throws
 * first). Symfony's own services resetter does the same between requests; no firewall has run yet, so nothing of the
 * current request is lost.
 *
 * When the main request finishes (HttpKernel dispatches `kernel.finish_request` after a response and on the exception
 * path alike), so the user is gone before `kernel.terminate` and before the next request, if:
 *
 * - the bundle's authenticator signed the request in ({@see ApiTokenContext::signedIn()}), whatever token the storage
 *   holds now: an `AuthenticationTokenCreatedEvent` listener (a 2FA bundle) or a stateless `switch_user` may have
 *   replaced the bundle's token with one that does not carry its attributes (audit ZAB-S2-L2); or
 * - the request went through a firewall that lists the authenticator (always stateless, see FirewallPass): nothing in
 *   its storage may outlive the request, and on a `lazy: true` firewall whose storage was never read this also drops
 *   the pending initializer, so a later read cannot authenticate this request's header for another request.
 *
 * It never reads the storage (`getToken()` would run a pending lazy-firewall initializer and authenticate after the
 * controller, audit ZAB-S2-L3): `setToken(null)` also drops such an initializer. It uses the untracked token storage,
 * so it never marks a session as used.
 */
final readonly class ApiTokenTokenStorageResetListener
{
    /** SecurityBundle's token storage without session usage tracking. */
    public const TOKEN_STORAGE = 'security.untracked_token_storage';

    /** Runs before the firewall (priority 8). */
    public const REQUEST_PRIORITY = 512;

    /** Runs after everything else of the request. */
    public const FINISH_PRIORITY = -1024;

    /** Request attribute where SecurityBundle's firewall map records the firewall context of the request. */
    private const FIREWALL_CONTEXT = '_firewall_context';

    /**
     * Wires the listener.
     *
     * @param list<string> $firewallContexts service ids of the firewall contexts (`security.firewall.map.context.<name>`)
     *                                       that list the bundle's authenticator (set by FirewallPass)
     */
    public function __construct(private TokenStorageInterface $tokenStorage, private array $firewallContexts = [])
    {
    }

    /**
     * Before any firewall of a main request: empties the storage, never reading it.
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->tokenStorage->setToken(null);
        }
    }

    /**
     * When the main request finishes (after the response, also after an exception).
     */
    public function onFinishRequest(FinishRequestEvent $event): void
    {
        if ($event->isMainRequest() && $this->ours($event->getRequest())) {
            $this->tokenStorage->setToken(null);
        }
    }

    private function ours(Request $request): bool
    {
        return ApiTokenContext::signedIn($request)
            || in_array($request->attributes->get(self::FIREWALL_CONTEXT), $this->firewallContexts, true);
    }
}
