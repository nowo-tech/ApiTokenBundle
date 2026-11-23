<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Fixtures;

use Nowo\ApiTokenBundle\Infrastructure\Security\ApiTokenFirewallAuthenticator;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;
use Symfony\Component\Security\Http\Event\AuthenticationTokenCreatedEvent;

/**
 * Stands for a 2FA bundle or a stateless `switch_user` (audit ZAB-S2-L2): replaces the bundle's security token right
 * after its creation with a token for the same user that carries none of the bundle's attributes.
 */
final class TokenReplacingListener
{
    #[AsEventListener(event: AuthenticationTokenCreatedEvent::class)]
    public function onTokenCreated(AuthenticationTokenCreatedEvent $event): void
    {
        $token = $event->getAuthenticatedToken();
        $user  = $token->getUser();
        if ($user instanceof UserInterface && $token->hasAttribute(ApiTokenFirewallAuthenticator::TOKEN_PRINCIPAL)) {
            $event->setAuthenticatedToken(new PostAuthenticationToken($user, 'replaced', $token->getRoleNames()));
        }
    }
}
