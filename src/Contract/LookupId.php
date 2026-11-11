<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Contract;

use Nowo\ApiTokenBundle\Contract\Exception\InvalidApiTokenArgumentException;

use function preg_match;

/**
 * The token's public, non-secret handle: 12 characters of `[a-z2-7]` (60 random bits), part of the token string and
 * stored in clear. Lists show it as `<prefix><first 4>…`.
 */
final readonly class LookupId
{
    /** Length of a lookup id. */
    public const LENGTH = 12;

    /**
     * @throws InvalidApiTokenArgumentException when the value is not 12 characters of [a-z2-7]
     */
    public function __construct(public string $value)
    {
        if (preg_match('/^[a-z2-7]{12}$/D', $value) !== 1) {
            throw new InvalidApiTokenArgumentException('Invalid lookup id: expected 12 characters of [a-z2-7].');
        }
    }

    /**
     * The lookup id string.
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
