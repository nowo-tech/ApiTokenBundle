<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Fixtures;

use Nowo\ApiTokenBundle\Contract\OwnerRef;
use Nowo\ApiTokenBundle\Contract\OwnerResolverInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Sign-in of the page tests (both host kinds): the `X-Test-User` header names the owner id; the user is loaded by the
 * host's owner resolver. A fully authenticated token, like a form login.
 */
final class TestUserAuthenticator extends AbstractAuthenticator
{
    public function __construct(private readonly OwnerResolverInterface $owners, private readonly string $ownerType)
    {
    }

    public function supports(Request $request): bool
    {
        return $request->headers->has('X-Test-User');
    }

    public function authenticate(Request $request): SelfValidatingPassport
    {
        $id = (string) $request->headers->get('X-Test-User');

        return new SelfValidatingPassport(new UserBadge($id, function () use ($id): UserInterface {
            $user = $this->owners->userOf(new OwnerRef($this->ownerType, $id));

            return $user instanceof UserInterface ? $user : throw new UserNotFoundException();
        }));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return new Response('', 401);
    }
}
