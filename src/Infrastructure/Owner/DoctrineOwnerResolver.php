<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Infrastructure\Owner;

use Doctrine\Persistence\ManagerRegistry;
use Nowo\ApiTokenBundle\Contract\Exception\InvalidApiTokenArgumentException;
use Nowo\ApiTokenBundle\Contract\OwnerRef;
use Nowo\ApiTokenBundle\Contract\OwnerResolverInterface;
use Stringable;

use function is_int;
use function is_string;
use function sprintf;

/**
 * Default {@see OwnerResolverInterface} of standard hosts: the user entity `owner.class`, its identifier field
 * `owner.id_field`, through the host's ORM; owner type `owner.type` (default `user`).
 *
 * The loaded user is re-checked with `===` on its id (audit LQ: a case-insensitive key lookup never returns another
 * person). Nothing is cached (worker mode): each call asks the entity manager.
 */
final readonly class DoctrineOwnerResolver implements OwnerResolverInterface
{
    /**
     * @param class-string $userClass
     */
    public function __construct(
        private ManagerRegistry $registry,
        private string $userClass,
        private string $idField = 'id',
        private string $type = 'user',
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function ownerOf(object $user): OwnerRef
    {
        if (!$user instanceof $this->userClass) {
            throw new InvalidApiTokenArgumentException(sprintf('The user is not a %s.', $this->userClass));
        }
        $manager = $this->registry->getManagerForClass($this->userClass);
        $id      = $manager?->getClassMetadata($this->userClass)->getIdentifierValues($user)[$this->idField] ?? null;
        $id      = $this->idString($id);
        if ($id === null) {
            throw new InvalidApiTokenArgumentException('The user has no identifier yet.');
        }

        return new OwnerRef($this->type, $id);
    }

    /**
     * {@inheritDoc}
     */
    public function userOf(OwnerRef $owner): ?object
    {
        if ($owner->type !== $this->type) {
            return null;
        }
        $user = $this->registry->getManagerForClass($this->userClass)?->getRepository($this->userClass)->findOneBy([$this->idField => $owner->id]);
        if ($user === null) {
            return null;
        }

        return $this->ownerOf($user)->equals($owner) ? $user : null;
    }

    private function idString(mixed $id): ?string
    {
        return match (true) {
            is_string($id) && $id !== '' => $id,
            is_int($id)                  => (string) $id,
            $id instanceof Stringable    => (string) $id,
            default                      => null,
        };
    }
}
