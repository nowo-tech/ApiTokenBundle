<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Application\Token;

use LogicException;
use Nowo\ApiTokenBundle\Domain\ApiToken;
use SensitiveParameter;

use function hash;
use function hash_equals;
use function hash_hmac;

/**
 * Hash at rest (`REQ-ENCRYPT-002`, Q-O3): version 1 = SHA-256 of the full token (default; the secret has 256 bits, so a
 * slow hash adds latency and no security); version 2 = HMAC-SHA256 with a pepper from the environment.
 *
 * @internal
 */
final readonly class TokenHasher implements TokenHasherInterface
{
    /**
     * @param int $version `hash.version`
     * @param string|null $pepper `hash.pepper`, required by version 2
     */
    public function __construct(private int $version = ApiToken::HASH_SHA256, #[SensitiveParameter] private ?string $pepper = null)
    {
        if ($version === ApiToken::HASH_HMAC_SHA256 && ($pepper === null || $pepper === '')) {
            throw new LogicException('nowo_api_token.hash.version 2 needs a non-empty hash.pepper.');
        }
    }

    /**
     * The configured version.
     */
    public function currentVersion(): int
    {
        return $this->version;
    }

    /**
     * Hash with the configured version.
     */
    public function hash(#[SensitiveParameter] string $token): string
    {
        return (string) $this->hashWith($token, $this->version);
    }

    /**
     * Constant-time comparison; an unknown version (or version 2 without a pepper) still runs `hash_equals()` and
     * answers false.
     */
    public function matches(#[SensitiveParameter] string $token, string $storedHash, int $version): bool
    {
        $computed = $this->hashWith($token, $version);

        return hash_equals($storedHash, $computed ?? hash('sha256', 'unknown-version:' . $token)) && $computed !== null;
    }

    private function hashWith(#[SensitiveParameter] string $token, int $version): ?string
    {
        return match (true) {
            $version === ApiToken::HASH_SHA256                                                        => hash('sha256', $token),
            $version === ApiToken::HASH_HMAC_SHA256 && $this->pepper !== null && $this->pepper !== '' => hash_hmac('sha256', $token, $this->pepper),
            default                                                                                   => null,
        };
    }
}
