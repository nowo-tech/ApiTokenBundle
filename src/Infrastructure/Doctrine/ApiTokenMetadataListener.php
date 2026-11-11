<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Infrastructure\Doctrine;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use LogicException;
use Nowo\ApiTokenBundle\Domain\ApiToken;
use Nowo\ApiTokenBundle\Infrastructure\Doctrine\Entity\ApiTokenRecord;
use Throwable;

use function in_array;
use function is_string;
use function str_replace;

/**
 * Applies `doctrine.table_prefix` to the token table and its index names (`uniq_<prefix>api_token_lookup`, REQ-ORM-002)
 * and, on MySQL / MariaDB, a binary collation to the identifier columns (audit LQ): `ascii_bin` for the id-like ascii
 * columns, `utf8mb4_bin` for `owner_id` and `name_key`. PostgreSQL and SQLite compare exactly by default and need nothing.
 *
 * Touches only the bundle's two mapped classes, on whichever entity manager maps them (standard and DDD hosts).
 */
final readonly class ApiTokenMetadataListener
{
    /** Unprefixed table name. */
    public const TABLE = 'api_token';

    /** Columns that hold ascii identifiers or hashes. */
    private const ASCII_COLUMNS = ['id', 'lookup_id', 'secret_hash', 'owner_type', 'legacy_hash'];

    /**
     * @param string $tablePrefix `doctrine.table_prefix`, may be empty
     */
    public function __construct(private string $tablePrefix)
    {
    }

    /**
     * Doctrine `loadClassMetadata` listener.
     */
    public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
    {
        $metadata = $args->getClassMetadata();
        if (!in_array($metadata->getName(), [ApiToken::class, ApiTokenRecord::class], true)) {
            return;
        }

        $table = ['name' => $this->tablePrefix . self::TABLE];
        foreach (['indexes', 'uniqueConstraints'] as $key) {
            $renamed = [];
            foreach ($metadata->table[$key] ?? [] as $name => $definition) {
                $renamed[is_string($name) ? str_replace('_' . self::TABLE . '_', '_' . $this->tablePrefix . self::TABLE . '_', $name) : $name] = $definition;
            }
            $table[$key] = $renamed;
        }
        $metadata->setPrimaryTable($table);

        try {
            $platform = $args->getObjectManager()->getConnection()->getDatabasePlatform();
        } catch (Throwable $exception) {
            throw new LogicException('nowo_api_token needs the database platform to map the identifier columns with a binary collation. Set doctrine.dbal.server_version (or make the database reachable) before warming up the cache.', 0, $exception);
        }
        if (!$platform instanceof AbstractMySQLPlatform) {
            return;
        }
        foreach ($metadata->fieldMappings as $field => $mapping) {
            if (in_array($mapping->columnName, self::ASCII_COLUMNS, true)) {
                $metadata->fieldMappings[$field]->options = ['charset' => 'ascii', 'collation' => 'ascii_bin'] + ($mapping->options ?? []);
            } elseif ($mapping->columnName === 'owner_id' || $mapping->columnName === 'name_key') {
                $metadata->fieldMappings[$field]->options = ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_bin'] + ($mapping->options ?? []);
            }
        }
    }
}
