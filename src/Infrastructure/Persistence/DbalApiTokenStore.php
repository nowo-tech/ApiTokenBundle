<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Infrastructure\Persistence;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Exception;
use JsonException;
use LogicException;
use Nowo\ApiTokenBundle\Contract\Exception\InvalidApiTokenArgumentException;
use Nowo\ApiTokenBundle\Contract\LookupId;
use Nowo\ApiTokenBundle\Contract\OwnerRef;
use Nowo\ApiTokenBundle\Contract\TokenId;
use Nowo\ApiTokenBundle\Domain\ApiToken;
use Nowo\ApiTokenBundle\Domain\ApiTokenStoreInterface;
use Nowo\ApiTokenBundle\Domain\LookupIdTakenException;
use Nowo\ApiTokenBundle\Infrastructure\Doctrine\ApiTokenMetadataListener;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function array_key_exists;
use function implode;
use function is_array;
use function is_int;
use function is_numeric;
use function is_object;
use function is_string;
use function json_decode;
use function json_encode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Default {@see ApiTokenStoreInterface}: plain DBAL on the connection of `doctrine.entity_manager` (contract §5.4).
 *
 * - Never flushes an EntityManager and never hydrates a managed entity: rows become {@see ApiToken} objects by hand.
 * - Writes run in a transaction opened and closed in the same call; inside a host transaction they join it and the
 *   after-commit work waits for the host's commit ({@see AfterCommitQueue}).
 * - Reads, and writes outside a host transaction, retry once when the connection was lost between requests.
 * - Times are stored and read as UTC. Every owner-scoped result is re-checked in PHP (`===`, audit LQ).
 * - No query compares the secret hash.
 *
 * Stateless (worker mode): the connection is resolved per call.
 */
final readonly class DbalApiTokenStore implements ApiTokenStoreInterface
{
    private const COLUMNS = 'id, lookup_id, secret_hash, hash_version, owner_type, owner_id, name, grants, reach_everything, reach, created_at, expires_at, last_used_at, created_via, legacy_hash';

    private string $table;

    /**
     * @param string $entityManager `doctrine.entity_manager` (falls back to a DBAL connection of that name)
     * @param string $tablePrefix `doctrine.table_prefix`
     */
    public function __construct(
        private ManagerRegistry $registry,
        private AfterCommitQueue $afterCommit,
        private string $entityManager = 'default',
        string $tablePrefix = 'nowo_',
        private ?LoggerInterface $logger = null,
    ) {
        $this->table = $tablePrefix . ApiTokenMetadataListener::TABLE;
    }

    /**
     * {@inheritDoc}
     */
    public function transactional(Closure $work, ?Closure $afterCommit = null): mixed
    {
        $connection = $this->connection();
        $joined     = $connection->isTransactionActive();
        $result     = $joined ? $connection->transactional($work) : $this->retrying($connection, static fn (Connection $c): mixed => $c->transactional($work));
        if (!$afterCommit instanceof Closure) {
            return $result;
        }
        $then = static function () use ($afterCommit, $result): void {
            $afterCommit($result);
        };
        $native = $joined ? $connection->getNativeConnection() : null;
        if (is_object($native) && $connection->isTransactionActive()) {
            $this->afterCommit->hold($native, $then);
        } else {
            $this->afterCommit->run($then);
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function insert(ApiToken $token): void
    {
        try {
            $this->connection()->insert($this->table, [
                'id'               => $token->id()->value,
                'lookup_id'        => $token->lookupId()->value,
                'secret_hash'      => $token->secretHash(),
                'hash_version'     => $token->hashVersion(),
                'owner_type'       => $token->owner()->type,
                'owner_id'         => $token->owner()->id,
                'name'             => $token->name(),
                'grants'           => json_encode($token->grants()->toArray(), JSON_THROW_ON_ERROR),
                'reach_everything' => $token->reach()->isEverything(),
                'reach'            => json_encode($token->reach()->storedEntries(), JSON_THROW_ON_ERROR),
                'created_at'       => $this->utc($token->createdAt()),
                'expires_at'       => $this->utc($token->expiresAt()),
                'last_used_at'     => $token->lastUsedAt() instanceof DateTimeImmutable ? $this->utc($token->lastUsedAt()) : null,
                'created_via'      => $token->createdVia(),
                'legacy_hash'      => $token->legacyHash(),
            ], [
                'hash_version'     => ParameterType::INTEGER,
                'reach_everything' => Types::BOOLEAN,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            throw new LookupIdTakenException('The lookup id or the token id already exists.', 0, $exception);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function findById(TokenId $id): ?ApiToken
    {
        $token = $this->one('id = ?', [$id->value]);

        return $token instanceof ApiToken && $token->id()->value === $id->value ? $token : null;
    }

    /**
     * {@inheritDoc}
     */
    public function findByLookupId(LookupId $lookupId): ?ApiToken
    {
        $token = $this->one('lookup_id = ?', [$lookupId->value]);

        return $token instanceof ApiToken && $token->lookupId()->value === $lookupId->value ? $token : null;
    }

    /**
     * {@inheritDoc}
     */
    public function ofOwner(OwnerRef $owner, int $limit): array
    {
        return $this->ownedBy($owner, $this->many(
            sprintf('SELECT %s FROM %s WHERE owner_type = ? AND owner_id = ? ORDER BY created_at DESC, id DESC', self::COLUMNS, $this->table),
            [$owner->type, $owner->id],
            $limit,
        ));
    }

    /**
     * {@inheritDoc}
     */
    public function countLive(OwnerRef $owner, DateTimeImmutable $now): int
    {
        $rows = $this->read(fn (Connection $c): array => $c->fetchAllAssociative(
            sprintf('SELECT owner_type, owner_id FROM %s WHERE owner_type = ? AND owner_id = ? AND expires_at > ?', $this->table),
            [$owner->type, $owner->id, $this->utc($now)],
        ));
        $count = 0;
        foreach ($rows as $row) {
            if ($row['owner_type'] === $owner->type && $row['owner_id'] === $owner->id) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * {@inheritDoc}
     */
    public function delete(TokenId $id): bool
    {
        return (int) $this->connection()->delete($this->table, ['id' => $id->value]) > 0;
    }

    /**
     * {@inheritDoc}
     */
    public function page(?TokenId $after, int $limit, ?OwnerRef $owner = null): array
    {
        $where  = [];
        $params = [];
        if ($after instanceof TokenId) {
            $where[]  = 'id < ?';
            $params[] = $after->value;
        }
        if ($owner instanceof OwnerRef) {
            $where[]  = 'owner_type = ? AND owner_id = ?';
            $params[] = $owner->type;
            $params[] = $owner->id;
        }
        $tokens = $this->many(
            sprintf('SELECT %s FROM %s%s ORDER BY id DESC', self::COLUMNS, $this->table, $where === [] ? '' : ' WHERE ' . implode(' AND ', $where)),
            $params,
            $limit,
        );

        return $owner instanceof OwnerRef ? $this->ownedBy($owner, $tokens) : $tokens;
    }

    /**
     * {@inheritDoc}
     */
    public function expiredBefore(DateTimeImmutable $cutoff, int $limit): array
    {
        return $this->many(
            sprintf('SELECT %s FROM %s WHERE expires_at < ? ORDER BY expires_at ASC, id ASC', self::COLUMNS, $this->table),
            [$this->utc($cutoff)],
            $limit,
        );
    }

    /**
     * {@inheritDoc}
     */
    public function touchLastUsed(TokenId $id, DateTimeImmutable $now, DateTimeImmutable $olderThan): bool
    {
        $connection = $this->connection();
        if ($connection->isTransactionActive()) {
            return false;
        }

        return (int) $connection->executeStatement(
            sprintf('UPDATE %s SET last_used_at = ? WHERE id = ? AND (last_used_at IS NULL OR last_used_at < ?)', $this->table),
            [$this->utc($now), $id->value, $this->utc($olderThan)],
        ) > 0;
    }

    /**
     * @param list<mixed> $params
     */
    private function one(string $condition, array $params): ?ApiToken
    {
        return $this->many(sprintf('SELECT %s FROM %s WHERE %s', self::COLUMNS, $this->table, $condition), $params, 1)[0] ?? null;
    }

    /**
     * @param list<mixed> $params
     *
     * @return list<ApiToken>
     */
    private function many(string $sql, array $params, int $limit): array
    {
        $rows   = $this->read(static fn (Connection $c): array => $c->fetchAllAssociative($c->getDatabasePlatform()->modifyLimitQuery($sql, $limit), $params));
        $tokens = [];
        foreach ($rows as $row) {
            $token = $this->hydrate($row);
            if ($token instanceof ApiToken) {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    /**
     * @param list<ApiToken> $tokens
     *
     * @return list<ApiToken>
     */
    private function ownedBy(OwnerRef $owner, array $tokens): array
    {
        $owned = [];
        foreach ($tokens as $token) {
            if ($token->owner()->equals($owner)) {
                $owned[] = $token;
            }
        }

        return $owned;
    }

    /**
     * A damaged row (invalid identifier or date) is skipped and logged: it can never authenticate or be listed.
     *
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ?ApiToken
    {
        try {
            return new ApiToken(
                $this->string($row, 'id'),
                $this->string($row, 'lookup_id'),
                $this->string($row, 'secret_hash'),
                $this->int($row, 'hash_version'),
                $this->string($row, 'owner_type'),
                $this->string($row, 'owner_id'),
                $this->string($row, 'name'),
                $this->json($row['grants'] ?? null),
                (bool) $row['reach_everything'],
                $this->json($row['reach'] ?? null),
                $this->date($this->string($row, 'created_at')),
                $this->date($this->string($row, 'expires_at')),
                is_string($row['last_used_at'] ?? null) ? $this->date($row['last_used_at']) : null,
                $this->string($row, 'created_via'),
                is_string($row['legacy_hash'] ?? null) ? $row['legacy_hash'] : null,
            );
        } catch (InvalidApiTokenArgumentException|LogicException $exception) {
            ($this->logger ?? new NullLogger())->warning('nowo_api_token: skipped a damaged token row: {message}', ['message' => $exception->getMessage()]);

            return null;
        }
    }

    /**
     * @template T
     *
     * @param Closure(Connection): T $operation
     *
     * @return T
     */
    private function read(Closure $operation): mixed
    {
        return $this->retrying($this->connection(), $operation);
    }

    /**
     * Runs the operation, and once more on a fresh connection when the first try lost the connection.
     *
     * @template T
     *
     * @param Closure(Connection): T $operation
     *
     * @return T
     */
    private function retrying(Connection $connection, Closure $operation): mixed
    {
        try {
            return $operation($connection);
        } catch (ConnectionLost $exception) {
            if ($connection->isTransactionActive()) {
                throw $exception;
            }
            ($this->logger ?? new NullLogger())->info('nowo_api_token: database connection lost, reconnecting once.');
            $connection->close();

            return $operation($connection);
        }
    }

    private function connection(): Connection
    {
        if (array_key_exists($this->entityManager, $this->registry->getManagerNames())) {
            $manager = $this->registry->getManager($this->entityManager);
            if ($manager instanceof EntityManagerInterface) {
                return $manager->getConnection();
            }
        }
        $connection = $this->registry->getConnection($this->entityManager);
        if (!$connection instanceof Connection) {
            throw new LogicException(sprintf('nowo_api_token.doctrine.entity_manager "%s" names neither an ORM entity manager nor a DBAL connection.', $this->entityManager));
        }

        return $connection;
    }

    private function utc(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format($this->connection()->getDatabasePlatform()->getDateTimeFormatString());
    }

    private function date(string $value): DateTimeImmutable
    {
        $utc    = new DateTimeZone('UTC');
        $format = $this->connection()->getDatabasePlatform()->getDateTimeFormatString();
        $date   = DateTimeImmutable::createFromFormat('!' . $format, $value, $utc);
        if ($date === false) {
            try {
                $date = new DateTimeImmutable($value, $utc);
            } catch (Exception $exception) {
                throw new LogicException('Invalid stored date.', 0, $exception);
            }
        }

        // @igor-ignore - DateTimeImmutable::setTimezone() returns a new value.
        return $date->setTimezone($utc);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function string(array $row, string $column): string
    {
        $value = $row[$column] ?? null;
        if (!is_string($value)) {
            throw new LogicException(sprintf('Column "%s" is not a string.', $column));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function int(array $row, string $column): int
    {
        $value = $row[$column] ?? null;
        if (!is_int($value) && !is_numeric($value)) {
            throw new LogicException(sprintf('Column "%s" is not a number.', $column));
        }

        return (int) $value;
    }

    /**
     * Decoded JSON array; anything else (damaged, not an array) becomes [] and narrows to nothing.
     *
     * @return array<mixed>
     */
    private function json(mixed $value): array
    {
        if (!is_string($value)) {
            return [];
        }
        try {
            $decoded = json_decode($value, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
