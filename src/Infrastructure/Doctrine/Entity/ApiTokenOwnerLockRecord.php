<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Infrastructure\Doctrine\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\ApiTokenBundle\Domain\ApiTokenOwnerLock;

/**
 * Attribute-mapped twin of {@see ApiTokenOwnerLock} (`doctrine.mapping: attribute`): the same table and columns. A test
 * asserts that both mappings produce the same schema. Read-only for hosts: the bundle writes through DBAL.
 *
 * Not final: Doctrine generates proxies of mapped classes at cache warm-up.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'api_token_owner_lock')]
class ApiTokenOwnerLockRecord
{
    /**
     * Holds the column values.
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'owner_type', type: Types::STRING, length: 32)]
        public readonly string $ownerType,
        #[ORM\Id]
        #[ORM\Column(name: 'owner_id', type: Types::STRING, length: 128)]
        public readonly string $ownerId,
        #[ORM\Column(name: 'version', type: Types::INTEGER)]
        public readonly int $version = 1,
    ) {
    }
}
