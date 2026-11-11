<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Contract;

use Nowo\ApiTokenBundle\Contract\Exception\InvalidApiTokenArgumentException;

use function preg_match;
use function sprintf;

/**
 * The person a token belongs to, by reference (ID mode, contract §5.2): a type and an id, never a Doctrine relation.
 *
 * The id is compared exactly (`===`, audit LQ): it is never trimmed or case-folded, holds 1 to 128 characters of valid
 * UTF-8 and no control character. The type matches `^[a-z][a-z0-9_]{0,31}$`.
 *
 * Copied per bundle in 1.0 (Q-O12): NotifyKit's `RecipientRef` and ReportKit's owner are the same idea.
 */
final readonly class OwnerRef
{
    /** Longest owner id, in characters (column `owner_id` is `string(128)`). */
    public const MAX_ID_LENGTH = 128;

    /**
     * @throws InvalidApiTokenArgumentException when the type or the id is invalid
     */
    public function __construct(public string $type, public string $id)
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $type) !== 1) {
            throw new InvalidApiTokenArgumentException('Invalid owner type: expected ^[a-z][a-z0-9_]{0,31}$.');
        }
        if (preg_match('/^[^\p{Cc}]{1,' . self::MAX_ID_LENGTH . '}$/Du', $id) !== 1) {
            throw new InvalidApiTokenArgumentException(sprintf('Invalid owner id: expected 1 to %d characters of valid UTF-8 without control characters.', self::MAX_ID_LENGTH));
        }
    }

    /**
     * `<type>:<id>`, e.g. `user:42`; for display and logs.
     */
    public function key(): string
    {
        return $this->type . ':' . $this->id;
    }

    /**
     * Strict equality of type and id (`===`, no case folding).
     */
    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->id === $other->id;
    }
}
