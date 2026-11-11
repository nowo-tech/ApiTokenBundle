<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Contract;

use SensitiveParameter;

/**
 * A freshly issued token: its view and the full token string, returned once. The secret is never stored, logged or
 * put into an event; a lost secret means a new token.
 */
final readonly class IssuedToken
{
    /**
     * @param string $secret the full token string (`<prefix><lookup id>_<secret><checksum>`)
     */
    public function __construct(public ApiTokenView $token, #[SensitiveParameter] public string $secret)
    {
    }

    /**
     * Never prints the secret.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['token' => $this->token, 'secret' => '[redacted]'];
    }
}
