<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Domain;

use DateTimeImmutable;
use Nowo\ApiTokenBundle\Contract\ApiTokenView;
use Nowo\ApiTokenBundle\Contract\Exception\InvalidApiTokenArgumentException;
use Nowo\ApiTokenBundle\Contract\Grants;
use Nowo\ApiTokenBundle\Contract\LookupId;
use Nowo\ApiTokenBundle\Contract\OwnerRef;
use Nowo\ApiTokenBundle\Contract\Reach;
use Nowo\ApiTokenBundle\Contract\TokenId;

use function mb_strtolower;

/**
 * A stored personal token (table `<prefix>api_token`). A plain PHP class with no mapping attributes: the bundle maps
 * it in XML (`Resources/config/doctrine-mapping/ApiToken.orm.xml`) for the schema and for read-only host queries, and writes
 * it only through DBAL ({@see ApiTokenStoreInterface}); the bundle never keeps it managed by an EntityManager.
 *
 * Holds the secret hash, so it never leaves the bundle: public code sees {@see ApiTokenView}.
 *
 * Not final: Doctrine generates proxies of mapped classes at cache warm-up.
 */
class ApiToken
{
    /** SHA-256 of the full token (Q-O3 default). */
    public const HASH_SHA256 = 1;

    /** HMAC-SHA256 of the full token with a pepper. */
    public const HASH_HMAC_SHA256 = 2;

    /** Longest token name, in characters. */
    public const MAX_NAME_LENGTH = 80;

    /** Case-insensitive key of {@see self::$name} (column `name_key`). */
    private string $nameKey;

    /**
     * Validates every identifier through its value object.
     *
     * @param array<mixed> $grants stored shape `{"levels": {…}, "flags": {…}}`
     * @param array<mixed> $reach limited entries, or the exceptions of *everything*
     *
     * @throws InvalidApiTokenArgumentException when an identifier is invalid
     */
    public function __construct(
        private string $id,
        private string $lookupId,
        private string $secretHash,
        private int $hashVersion,
        private string $ownerType,
        private string $ownerId,
        private string $name,
        private array $grants,
        private bool $reachEverything,
        private array $reach,
        private DateTimeImmutable $createdAt,
        private DateTimeImmutable $expiresAt,
        private ?DateTimeImmutable $lastUsedAt = null,
        private string $createdVia = 'account',
        private ?string $legacyHash = null,
    ) {
        new TokenId($id);
        new LookupId($lookupId);
        new OwnerRef($ownerType, $ownerId);
        $this->nameKey = mb_strtolower($name);
    }

    /**
     * The lower-cased name: unique per owner (`uniq_<prefix>api_token_owner_name`), so two tokens of one owner never
     * share a name, even when issued concurrently.
     */
    public function nameKey(): string
    {
        return $this->nameKey;
    }

    /**
     * The row id.
     */
    public function id(): TokenId
    {
        return new TokenId($this->id);
    }

    /**
     * The public lookup id.
     */
    public function lookupId(): LookupId
    {
        return new LookupId($this->lookupId);
    }

    /**
     * Lower-case hex hash of the full token (see {@see self::hashVersion()}).
     */
    public function secretHash(): string
    {
        return $this->secretHash;
    }

    /**
     * 1 = SHA-256, 2 = HMAC-SHA256 with pepper, 0 = Beacon legacy (S5).
     */
    public function hashVersion(): int
    {
        return $this->hashVersion;
    }

    /**
     * The owner.
     */
    public function owner(): OwnerRef
    {
        return new OwnerRef($this->ownerType, $this->ownerId);
    }

    /**
     * The token's name.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * The stored grants (not yet narrowed by the registry).
     */
    public function grants(): Grants
    {
        return Grants::fromStored($this->grants);
    }

    /**
     * The stored reach; damaged entries fail closed.
     */
    public function reach(): Reach
    {
        return Reach::fromStored($this->reachEverything, $this->reach);
    }

    /**
     * Creation time (UTC).
     */
    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Expiry time (UTC); always set.
     */
    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /**
     * Last authentication time (UTC, throttled to one write a minute), or null.
     */
    public function lastUsedAt(): ?DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    /**
     * `account`, `cli`, ….
     */
    public function createdVia(): string
    {
        return $this->createdVia;
    }

    /**
     * Full-token SHA-256 of a migrated Beacon v1 token (S5), else null.
     */
    public function legacyHash(): ?string
    {
        return $this->legacyHash;
    }

    /**
     * Whether the token is expired at this time (`expires_at <= now`).
     */
    public function isExpiredAt(DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }

    /**
     * The public view (no secret, no hash).
     */
    public function toView(): ApiTokenView
    {
        return new ApiTokenView(
            $this->id(),
            $this->lookupId(),
            $this->owner(),
            $this->name,
            $this->grants(),
            $this->reach(),
            $this->createdAt,
            $this->expiresAt,
            $this->lastUsedAt,
            $this->createdVia,
        );
    }
}
