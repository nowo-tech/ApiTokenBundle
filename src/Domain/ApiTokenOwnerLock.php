<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Domain;

/**
 * The per-owner issue lock (table `<prefix>api_token_owner_lock`, REQ-RUNTIME-003 rule 4): one row per owner that ever
 * issued a token, keyed by (`owner_type`, `owner_id`). The issuer updates it (`version = version + 1`) before it
 * counts the owner's live tokens and checks the name, so concurrent issues for one owner run one after the other and
 * the live-token cap (`max_per_owner`) holds. The row carries no business data.
 *
 * A plain PHP class with no mapping attributes, mapped in XML for the schema only
 * (`Resources/config/doctrine-mapping/ApiTokenOwnerLock.orm.xml`); written only through DBAL
 * ({@see ApiTokenStoreInterface::lockOwner()}), never managed by an EntityManager.
 *
 * Not final: Doctrine generates proxies of mapped classes at cache warm-up.
 */
class ApiTokenOwnerLock
{
    /**
     * Holds the column values.
     */
    public function __construct(
        public readonly string $ownerType,
        public readonly string $ownerId,
        public readonly int $version = 1,
    ) {
    }
}
