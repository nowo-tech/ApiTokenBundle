<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Fixtures\Standard;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Standard host: an attribute-mapped user entity on the default entity manager, with a string id so tests can create
 * `UserA` and `usera` side by side (audit LQ).
 */
#[ORM\Entity]
#[ORM\Table(name: 'app_user')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    /**
     * @param non-empty-string $id
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::STRING, length: 128)]
        public string $id,
        #[ORM\Column(type: Types::BOOLEAN)]
        public bool $active = true,
        #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
        public ?string $password = 'secret-pass',
    ) {
    }

    public function getRoles(): array
    {
        return $this->id === 'admin' ? ['ROLE_USER', 'ROLE_ADMIN'] : ['ROLE_USER'];
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return $this->id;
    }
}
