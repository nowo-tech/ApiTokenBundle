<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Fixtures;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

use function str_starts_with;

/**
 * Another authenticator (audit ZAB-S2-L1, L3): it owns `Basic …` values and signs them in as `basic-user`; anything
 * else is not its business. It is lazy (`supports()` answers null): on a stateful `lazy: true` firewall it only runs
 * when the token storage is read, and it counts its runs.
 */
final class ForeignSchemeAuthenticator extends AbstractAuthenticator
{
    public int $runs = 0;

    public function supports(Request $request): ?bool
    {
        return str_starts_with((string) $request->headers->get('Authorization', ''), 'Basic ') ? null : false;
    }

    public function authenticate(Request $request): SelfValidatingPassport
    {
        ++$this->runs;

        return new SelfValidatingPassport(new UserBadge('basic-user', static fn (): InMemoryUser => new InMemoryUser('basic-user', null)));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return null;
    }
}
