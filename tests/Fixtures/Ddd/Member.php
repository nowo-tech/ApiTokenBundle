<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Fixtures\Ddd;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * DDD host: a domain class mapped in XML on the host's own `ddd` entity manager, also the host's security user (so
 * its own user checker can judge it).
 */
class Member implements UserInterface
{
    public function __construct(private string $memberId, private bool $suspended = false)
    {
    }

    public function memberId(): string
    {
        return $this->memberId;
    }

    public function isSuspended(): bool
    {
        return $this->suspended;
    }

    public function getRoles(): array
    {
        return ['ROLE_MEMBER'];
    }

    public function eraseCredentials(): void
    {
    }

    /**
     * @return non-empty-string
     */
    public function getUserIdentifier(): string
    {
        return $this->memberId !== '' ? $this->memberId : 'member';
    }

    public function suspend(): void
    {
        $this->suspended = true;
    }
}
