<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Infrastructure\Owner;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\ApiTokenBundle\Contract\Exception\InvalidApiTokenArgumentException;
use Nowo\ApiTokenBundle\Contract\OwnerRef;
use Nowo\ApiTokenBundle\Contract\OwnerResolverInterface;
use Stringable;

use function is_int;
use function is_object;
use function is_string;
use function sprintf;

/**
 * Default {@see OwnerResolverInterface} of standard hosts: the user entity `owner.class`, its identifier field
 * `owner.id_field`, through the host's ORM; owner type `owner.type` (default `user`).
 *
 * Worker mode (audit LW-M2): every {@see self::userOf()} re-reads the row with a refreshing query, so an account
 * disabled by another process or worker is seen on the next request even when the user is already managed by the
 * host's long-lived EntityManager. A user the query had to load is detached again, so the identity map does not grow
 * with each owner; the principal then holds a detached snapshot (load it through your own EntityManager to change
 * it). A user the host already manages is refreshed in place and stays managed.
 *
 * The loaded user is re-checked with `===` on its id (audit LQ: a case-insensitive key lookup never returns another
 * person). Nothing is cached.
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
        $manager = $this->registry->getManagerForClass($this->userClass);
        if (!$manager instanceof EntityManagerInterface) {
            throw new LogicException(sprintf('nowo_api_token.owner.class "%s" is not mapped by a Doctrine ORM entity manager.', $this->userClass));
        }
        $unitOfWork = $manager->getUnitOfWork();
        $before     = $unitOfWork->size();
        $user       = $manager->createQueryBuilder()
            ->select('u')
            ->from($this->userClass, 'u')
            ->where(sprintf('u.%s = :id', $this->idField))
            ->setParameter('id', $owner->id)
            ->setMaxResults(1)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
        if (!is_object($user)) {
            return null;
        }
        if ($unitOfWork->size() > $before) {
            $manager->detach($user);
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
