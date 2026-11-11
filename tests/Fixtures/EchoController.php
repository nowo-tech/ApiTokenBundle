<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Fixtures;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Host controller of the worker test: answers only from the current request and a container parameter.
 */
final readonly class EchoController
{
    public function __construct(private string $apiPrefix)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        return new JsonResponse(['owner' => $request->headers->get('X-Test-Owner'), 'api_prefix' => $this->apiPrefix]);
    }
}
