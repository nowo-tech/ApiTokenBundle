<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Infrastructure\Security;

use Nowo\ApiTokenBundle\Contract\ReauthGateInterface;
use SensitiveParameter;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

use function is_string;
use function strlen;

/**
 * Default {@see ReauthGateInterface} (contract §4.3, Q-O4 until AuthKit's step-up): the person re-enters the password,
 * checked with the host's password hasher. A user without a password (SSO) never passes it: such hosts configure
 * `reauth.gate`.
 */
final readonly class PasswordReauthGate implements ReauthGateInterface
{
    /** Longest password the gate hashes (bounds the hashing work). */
    public const MAX_PASSWORD_LENGTH = 4096;

    /**
     * Wires the gate.
     */
    public function __construct(private UserPasswordHasherInterface $hasher)
    {
    }

    /**
     * Always false: only the bundle's own freshness mark counts.
     */
    public function isFresh(object $user, #[SensitiveParameter] string $sessionKey): bool
    {
        return false;
    }

    /**
     * Null: the bundle's password field.
     */
    public function form(): ?string
    {
        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function verify(object $user, #[SensitiveParameter] array $data): bool
    {
        $password = $data['password'] ?? null;
        if (!$user instanceof PasswordAuthenticatedUserInterface || !is_string($password) || $password === '' || strlen($password) > self::MAX_PASSWORD_LENGTH) {
            return false;
        }

        return $this->hasher->isPasswordValid($user, $password);
    }
}
