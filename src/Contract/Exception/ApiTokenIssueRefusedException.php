<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Contract\Exception;

use Nowo\ApiTokenBundle\Contract\ApiTokenPolicyInterface;
use RuntimeException;

/**
 * The issuer refused a well-formed request (policy, limit, duplicate name, reach outside the choices…).
 * {@see self::$reason} is a stable machine code for the UI; the message never carries a secret.
 */
final class ApiTokenIssueRefusedException extends RuntimeException
{
    /** The owner may not issue tokens ({@see ApiTokenPolicyInterface::mayIssue()}). */
    public const POLICY = 'policy';

    /** The owner does not exist (resolver) or is not active (owner status). */
    public const OWNER = 'owner';

    /** The owner already has `max_per_owner` live tokens. */
    public const TOO_MANY = 'too_many';

    /** Another token of the owner has the same name (case-insensitive). */
    public const DUPLICATE_NAME = 'duplicate_name';

    /** The grants name a module, level or flag that is unknown, disabled or not offered to the owner. */
    public const GRANTS = 'grants';

    /** The reach is not allowed (everything off, empty list, entry outside the owner's choices, too many entries). */
    public const REACH = 'reach';

    /** The expiry is outside 1 … the owner's maximum (≤ 365 days). */
    public const EXPIRY = 'expiry';

    /** The module switch is off. */
    public const DISABLED = 'disabled';

    /**
     * @param string $reason one of the class constants
     */
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
