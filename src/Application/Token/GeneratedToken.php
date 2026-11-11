<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Application\Token;

use JsonSerializable;
use LogicException;
use Nowo\ApiTokenBundle\Contract\IssuedToken;
use Nowo\ApiTokenBundle\Contract\LookupId;
use Nowo\ApiTokenBundle\Contract\SensitiveValue;
use SensitiveParameter;

/**
 * A new token string and its lookup id, before it is hashed and stored. Like
 * {@see IssuedToken}, the token string is readable through {@see self::token()} only and
 * never through `json_encode()`, `serialize()`, `var_export()`, an `(array)` cast or a dumper.
 *
 * @internal
 */
final readonly class GeneratedToken implements JsonSerializable
{
    private SensitiveValue $token;

    /**
     * Holds the parts.
     */
    public function __construct(public LookupId $lookupId, #[SensitiveParameter] string $token)
    {
        $this->token = new SensitiveValue($token);
    }

    /**
     * The full token string.
     */
    public function token(): string
    {
        return $this->token->reveal();
    }

    /**
     * The lookup id and a redacted token.
     *
     * @return array{lookupId: LookupId, token: string}
     */
    public function jsonSerialize(): array
    {
        return ['lookupId' => $this->lookupId, 'token' => '[redacted]'];
    }

    /**
     * Never prints the token.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['lookupId' => $this->lookupId, "\0" . self::class . "\0token" => '[redacted]'];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('A generated API token cannot be serialized.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('A generated API token cannot be unserialized.');
    }
}
