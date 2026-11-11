<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Fixtures;

use Nowo\ApiTokenBundle\Contract\ApiTokenAuthenticatorInterface;
use Nowo\ApiTokenBundle\Contract\ApiTokenPrincipal;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

use function preg_replace;

/**
 * Host API of the worker test: authenticates the Authorization header with the S1 authenticator service and answers
 * the principal (or the failure kind). The firewall that does this in production lands in S2.
 */
final readonly class AuthEchoController
{
    public function __construct(private ApiTokenAuthenticatorInterface $authenticator)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $result = $this->authenticator->authenticate((string) preg_replace('/^Bearer\s+/i', '', (string) $request->headers->get('Authorization', '')));
        if (!$result instanceof ApiTokenPrincipal) {
            return new JsonResponse(['failure' => $result->value], 401);
        }

        return new JsonResponse([
            'owner'  => $result->owner->key(),
            'token'  => $result->tokenId->value,
            'grants' => $result->grants->levels,
        ]);
    }
}
