<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Application\Token;

use SensitiveParameter;

/**
 * Hashes tokens at rest and compares them in constant time.
 *
 * @internal replaceable only for tests (timing guard)
 */
interface TokenHasherInterface
{
    /**
     * The hash version new tokens get.
     */
    public function currentVersion(): int;

    /**
     * Lower-case hex hash of the full token with the current version.
     */
    public function hash(#[SensitiveParameter] string $token): string;

    /**
     * Whether the token matches the stored hash of that version; always through `hash_equals()`.
     */
    public function matches(#[SensitiveParameter] string $token, string $storedHash, int $version): bool;
}
