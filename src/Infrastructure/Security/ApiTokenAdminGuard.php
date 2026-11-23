<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Infrastructure\Security;

use Nowo\ApiTokenBundle\Contract\ApiTokenAccessCheckerInterface;
use Nowo\ApiTokenBundle\Contract\ApiTokenView;
use Nowo\ApiTokenBundle\Contract\Event\ApiTokenAccessCheckEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use stdClass;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The operator page's access (`REQ-UI-002`): a user signed in fully (`IS_AUTHENTICATED_FULLY`: not remembered, unless
 * `allow_unauthenticated`, demo only) and not through an API token, whom the {@see ApiTokenAccessCheckerInterface}
 * lets in, and a per-token {@see ApiTokenAccessCheckEvent} before a revoke. Every operator action calls it; the host's
 * `access_control` on `admin_ui.path_prefix` is the first layer.
 *
 * The page is not scoped to a tenant: it lists every token of the application. Multi-tenant hosts restrict it through
 * `access_checker` (super-admins only) or an {@see ApiTokenAccessCheckEvent} listener.
 */
final readonly class ApiTokenAdminGuard
{
    /**
     * @param bool $allowUnauthenticated `admin_ui.security.allow_unauthenticated`
     * @param AuthorizationCheckerInterface|null $authorization for `IS_AUTHENTICATED_FULLY` (null: not checked)
     * @param RequestStack|null $requests to recognise a request the API-token authenticator signed in
     */
    public function __construct(
        private ApiTokenAccessCheckerInterface $checker,
        private EventDispatcherInterface $dispatcher,
        private ?TokenStorageInterface $tokenStorage = null,
        private bool $allowUnauthenticated = false,
        private ?AuthorizationCheckerInterface $authorization = null,
        private ?RequestStack $requests = null,
    ) {
    }

    /**
     * The operator, or a 403 (the page-level check).
     *
     * @throws AccessDeniedException when nobody is signed in fully, the request uses an API token, or the checker refuses
     */
    public function operator(): object
    {
        $token = $this->tokenStorage?->getToken();
        if (ApiTokenContext::signedInWithToken($token, $this->requests?->getMainRequest())) {
            throw new AccessDeniedException('API tokens cannot manage API tokens.');
        }
        $user = $token?->getUser();
        if (!$user instanceof UserInterface) {
            if (!$this->allowUnauthenticated) {
                throw new AccessDeniedException('Sign in to manage API tokens.');
            }
            $user = new stdClass();
        } elseif ($this->authorization instanceof AuthorizationCheckerInterface && !$this->authorization->isGranted('IS_AUTHENTICATED_FULLY')) {
            throw new AccessDeniedException('Sign in again to manage API tokens.');
        }
        if (!$this->checker->canAccess($user)) {
            throw new AccessDeniedException('Not allowed to manage API tokens.');
        }

        return $user;
    }

    /**
     * Refuses the action on this token when a listener denies it (403).
     *
     * @throws AccessDeniedHttpException when denied
     */
    public function assertCan(object $operator, ApiTokenView $token, string $action): void
    {
        $event = new ApiTokenAccessCheckEvent($operator, $token, $action);
        $this->dispatcher->dispatch($event);
        if (!$event->isGranted()) {
            throw new AccessDeniedHttpException('Not allowed on this API token.');
        }
    }
}
