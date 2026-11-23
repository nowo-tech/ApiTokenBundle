<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Contract;

use SensitiveParameter;

/**
 * Host SPI (contract §4.3, §6.10, `REQ-AUTH-013`): the step-up check before a token is issued and before *Revoke all*.
 * Default: the bundle's password gate (the person re-enters the password). A host with SSO only implements it with its
 * IdP's step-up; the AuthKit adapter (S4) adds MFA.
 *
 * The bundle keeps the freshness mark itself (a session value bound to the session id, `reauth.fresh_seconds`) and
 * limits the attempts (`reauth.max_attempts` per 15 minutes and session); the gate only answers the check.
 */
interface ReauthGateInterface
{
    /**
     * Whether the gate already knows a fresh step-up of this person in this session (e.g. an MFA step a moment ago).
     * The password gate answers false: only the bundle's own mark counts.
     *
     * @param object $user the signed-in user
     * @param string $sessionKey the session id
     */
    public function isFresh(object $user, #[SensitiveParameter] string $sessionKey): bool;

    /**
     * The form type class of the re-auth step, or null for the bundle's password field (`reauth_password`).
     *
     * @return class-string|null
     */
    public function form(): ?string;

    /**
     * Checks the submitted re-auth data (the fields of {@see self::form()}, or `['password' => …]`).
     *
     * @param array<string, mixed> $data
     */
    public function verify(object $user, #[SensitiveParameter] array $data): bool;
}
