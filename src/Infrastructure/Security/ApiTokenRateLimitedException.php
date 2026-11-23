<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Infrastructure\Security;

use Nowo\ApiTokenBundle\Contract\RateLimitState;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * The request was refused by a rate limit before or after the token check (internal to the firewall authenticator):
 * the client IP has too many failed tokens, or the token used its request or write budget.
 */
final class ApiTokenRateLimitedException extends AuthenticationException
{
    /** The constant message and message key. */
    public const MESSAGE = 'Too many requests';

    /**
     * Wraps the refused state.
     */
    public function __construct(public readonly RateLimitState $state)
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
