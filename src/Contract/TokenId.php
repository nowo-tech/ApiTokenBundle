<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Contract;

use Nowo\ApiTokenBundle\Contract\Exception\InvalidApiTokenArgumentException;

use function preg_match;

/**
 * A token's row id: a lower-case RFC 4122 UUID string (UUIDv7 for every token the bundle issues).
 */
final readonly class TokenId
{
    /**
     * @throws InvalidApiTokenArgumentException when the value is not a lower-case RFC 4122 UUID
     */
    public function __construct(public string $value)
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) !== 1) {
            throw new InvalidApiTokenArgumentException('Invalid token id: expected a lower-case RFC 4122 UUID.');
        }
    }

    /**
     * Whether both ids are the same.
     */
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * The UUID string.
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
