<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Infrastructure\Doctrine\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\ApiTokenBundle\Domain\ApiToken;

/**
 * Attribute-mapped twin of {@see ApiToken} (contract §5.1.1, Q-O11): the same table and
 * columns, for hosts whose tooling only reads attributes (`doctrine.mapping: attribute`). A test asserts that both
 * mappings produce the same schema. Read-only for hosts: the bundle writes through DBAL.
 *
 * Not final: Doctrine generates proxies of mapped classes at cache warm-up.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'api_token')]
#[ORM\Index(name: 'idx_api_token_owner', columns: ['owner_type', 'owner_id', 'created_at'])]
#[ORM\Index(name: 'idx_api_token_expires', columns: ['expires_at'])]
#[ORM\UniqueConstraint(name: 'uniq_api_token_lookup', columns: ['lookup_id'])]
#[ORM\UniqueConstraint(name: 'uniq_api_token_legacy', columns: ['legacy_hash'])]
class ApiTokenRecord
{
    /**
     * Holds the column values.
     *
     * @param array<mixed> $grants
     * @param array<mixed> $reach
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'id', type: Types::STRING, length: 36)]
        public readonly string $id,
        #[ORM\Column(name: 'lookup_id', type: Types::STRING, length: 12)]
        public readonly string $lookupId,
        #[ORM\Column(name: 'secret_hash', type: Types::STRING, length: 64)]
        public readonly string $secretHash,
        #[ORM\Column(name: 'hash_version', type: Types::SMALLINT)]
        public readonly int $hashVersion,
        #[ORM\Column(name: 'owner_type', type: Types::STRING, length: 32)]
        public readonly string $ownerType,
        #[ORM\Column(name: 'owner_id', type: Types::STRING, length: 128)]
        public readonly string $ownerId,
        #[ORM\Column(name: 'name', type: Types::STRING, length: 80)]
        public readonly string $name,
        #[ORM\Column(name: 'grants', type: Types::JSON)]
        public readonly array $grants,
        #[ORM\Column(name: 'reach_everything', type: Types::BOOLEAN)]
        public readonly bool $reachEverything,
        #[ORM\Column(name: 'reach', type: Types::JSON)]
        public readonly array $reach,
        #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
        public readonly DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE)]
        public readonly DateTimeImmutable $expiresAt,
        #[ORM\Column(name: 'last_used_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
        public readonly ?DateTimeImmutable $lastUsedAt,
        #[ORM\Column(name: 'created_via', type: Types::STRING, length: 16)]
        public readonly string $createdVia,
        #[ORM\Column(name: 'legacy_hash', type: Types::STRING, length: 64, nullable: true)]
        public readonly ?string $legacyHash,
    ) {
    }
}
