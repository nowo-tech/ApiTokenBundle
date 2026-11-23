<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Infrastructure\Security;

use Nowo\ApiTokenBundle\Contract\AuthFailure;
use Nowo\ApiTokenBundle\Contract\LookupId;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * A presented token was refused (internal to the firewall authenticator). The message is constant: it never carries
 * the presented value, and every failure kind answers the client the same way (contract §6.3).
 */
final class ApiTokenAuthenticationException extends AuthenticationException
{
    /** The constant message and message key. */
    public const MESSAGE = 'Invalid token';

    /**
     * @param LookupId|null $lookupId the presented lookup id when the token exists, for the failure event only
     */
    public function __construct(public readonly AuthFailure $failure, public readonly ?LookupId $lookupId = null)
    {
        parent::__construct(self::MESSAGE);
    }

    /**
     * The constant message key.
     */
    public function getMessageKey(): string
    {
        return self::MESSAGE;
    }
}
