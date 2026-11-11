<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Application\Token;

use Nowo\ApiTokenBundle\Contract\LookupId;
use SensitiveParameter;

/**
 * A new token string and its lookup id, before it is hashed and stored.
 *
 * @internal
 */
final readonly class GeneratedToken
{
    /**
     * Holds the parts.
     */
    public function __construct(public LookupId $lookupId, #[SensitiveParameter] public string $token)
    {
    }

    /**
     * Never prints the token.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['lookupId' => $this->lookupId, 'token' => '[redacted]'];
    }
}
