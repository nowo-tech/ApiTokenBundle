<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Contract\Event;

/**
 * Event names: the PSR-14 event class names.
 */
final class ApiTokenEvents
{
    /** {@see ApiTokenIssuedEvent} */
    public const ISSUED = ApiTokenIssuedEvent::class;

    /** {@see ApiTokenRevokedEvent} */
    public const REVOKED = ApiTokenRevokedEvent::class;

    /** {@see ApiTokenUsedEvent} */
    public const USED = ApiTokenUsedEvent::class;

    /** {@see ApiTokenAuthFailedEvent} */
    public const AUTH_FAILED = ApiTokenAuthFailedEvent::class;

    /** {@see ApiTokenAccessCheckEvent} */
    public const ACCESS_CHECK = ApiTokenAccessCheckEvent::class;
}
