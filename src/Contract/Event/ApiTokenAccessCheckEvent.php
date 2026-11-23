<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Contract\Event;

use Nowo\ApiTokenBundle\Contract\ApiTokenAccessCheckerInterface;
use Nowo\ApiTokenBundle\Contract\ApiTokenView;

/**
 * Per-token decision of the operator page (`REQ-UI-002`, `ApiTokenEvents::ACCESS_CHECK`): dispatched before an
 * operator revokes a token, after the page-level {@see ApiTokenAccessCheckerInterface}
 * check. A listener may deny (e.g. tokens of another organisation) or grant; without an answer the default stands
 * (granted). A deny wins over a grant.
 */
final class ApiTokenAccessCheckEvent
{
    /** The operator revokes the token. */
    public const ACTION_REVOKE = 'revoke';

    private ?bool $decision = null;

    private bool $denied = false;

    /**
     * @param object $user the operator
     * @param string $action {@see self::ACTION_REVOKE}
     */
    public function __construct(public readonly object $user, public readonly ApiTokenView $token, public readonly string $action)
    {
    }

    /**
     * Allows the action (a deny from another listener still wins).
     */
    public function grant(): void
    {
        // @igor-ignore - Per-dispatch event DTO; not a shared worker service.
        $this->decision ??= true;
    }

    /**
     * Refuses the action.
     */
    public function deny(): void
    {
        // @igor-ignore - Per-dispatch event DTO; not a shared worker service.
        $this->denied = true;
        // @igor-ignore - Per-dispatch event DTO; not a shared worker service.
        $this->decision = false;
    }

    /**
     * Whether the action is allowed: false after any deny, else true (granted or no answer).
     */
    public function isGranted(): bool
    {
        return !$this->denied;
    }

    /**
     * Whether a listener answered.
     */
    public function hasDecision(): bool
    {
        return $this->decision !== null;
    }
}
