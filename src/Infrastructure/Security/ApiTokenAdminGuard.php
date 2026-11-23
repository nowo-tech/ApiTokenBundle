<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Infrastructure\Security;

use Nowo\ApiTokenBundle\Contract\ApiTokenAccessCheckerInterface;
use Nowo\ApiTokenBundle\Contract\ApiTokenView;
use Nowo\ApiTokenBundle\Contract\Event\ApiTokenAccessCheckEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use stdClass;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The operator page's access (`REQ-UI-002`): a signed-in user (unless `allow_unauthenticated`, demo only) whom the
 * {@see ApiTokenAccessCheckerInterface} lets in, and a per-token {@see ApiTokenAccessCheckEvent} before a revoke.
 * Every operator action calls it; the host's `access_control` on `admin_ui.path_prefix` is the first layer.
 */
final readonly class ApiTokenAdminGuard
{
    /**
     * @param bool $allowUnauthenticated `admin_ui.security.allow_unauthenticated`
     */
    public function __construct(
        private ApiTokenAccessCheckerInterface $checker,
        private EventDispatcherInterface $dispatcher,
        private ?TokenStorageInterface $tokenStorage = null,
        private bool $allowUnauthenticated = false,
    ) {
    }

    /**
     * The operator, or a 403 (the page-level check).
     *
     * @throws AccessDeniedException when nobody is signed in or the checker refuses
     */
    public function operator(): object
    {
        $user = $this->tokenStorage?->getToken()?->getUser();
        if (!$user instanceof UserInterface) {
            if (!$this->allowUnauthenticated) {
                throw new AccessDeniedException('Sign in to manage API tokens.');
            }
            $user = new stdClass();
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
