<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Fixtures;

use Nowo\ApiTokenBundle\Application\Token\TokenHasher;
use Nowo\ApiTokenBundle\Application\Token\TokenHasherInterface;

/**
 * Timing guard probe: counts the constant-time comparisons and records the stored hash each one used.
 */
final class SpyHasher implements TokenHasherInterface
{
    /** @var list<string> */
    public array $compared = [];

    public function __construct(private readonly TokenHasher $inner = new TokenHasher())
    {
    }

    public function currentVersion(): int
    {
        return $this->inner->currentVersion();
    }

    public function hash(string $token): string
    {
        return $this->inner->hash($token);
    }

    public function matches(string $token, string $storedHash, int $version): bool
    {
        $this->compared[] = $storedHash;

        return $this->inner->matches($token, $storedHash, $version);
    }
}
