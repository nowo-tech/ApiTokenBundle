<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Fixtures;

use Nowo\ApiTokenBundle\Contract\ApiTokenPrincipal;
use Nowo\ApiTokenBundle\Infrastructure\Security\ApiTokenContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Host API behind the S2 firewall (`/api/whoami`): answers what the firewall stamped on the request
 * ({@see ApiTokenContext}) and who the security token storage holds, or the host's own 401 when there is no principal
 * (`on_failure: pass`).
 */
final readonly class WhoAmIController
{
    public function __construct(private TokenStorageInterface $tokenStorage)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $principal = ApiTokenContext::current($request);
        $rate      = ApiTokenContext::rateLimit($request);
        $user      = $this->tokenStorage->getToken()?->getUserIdentifier();
        if (!$principal instanceof ApiTokenPrincipal) {
            return new JsonResponse(['host' => 'no token', 'user' => $user, 'rate' => $rate?->scope->value, 'accepted' => $rate?->accepted], $rate?->accepted === false ? 429 : 401);
        }

        return new JsonResponse([
            'owner'     => $principal->owner->key(),
            'token'     => $principal->tokenId->value,
            'grants'    => $principal->grants->levels,
            'reach'     => $principal->reach->summary(),
            'user'      => $user,
            'rate'      => $rate?->scope->value,
            'remaining' => $rate?->remaining,
        ]);
    }
}
