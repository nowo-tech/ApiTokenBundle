<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Integration\Database;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ApiTokenBundle\Domain\ApiToken;
use Nowo\ApiTokenBundle\Infrastructure\Doctrine\Entity\ApiTokenRecord;
use Nowo\ApiTokenBundle\Tests\Integration\Kernel\Host;
use Nowo\ApiTokenBundle\Tests\Integration\Kernel\KernelTestCase;

use function array_keys;
use function ksort;
use function sort;

/**
 * Mapping (contract §5.1, §5.1.1): the XML mapping of the plain domain class and the attribute twin give the same
 * table; the prefix applies to the table and the index names; MySQL gets binary collations on the identifiers.
 */
final class SchemaTest extends KernelTestCase
{
    public function testXmlAndAttributeMappingsGiveTheSameTable(): void
    {
        $xml       = $this->table(['doctrine' => ['mapping' => 'xml']], ApiToken::class);
        $attribute = $this->table(['doctrine' => ['mapping' => 'attribute']], ApiTokenRecord::class);

        self::assertSame('nowo_api_token', $xml->getName());
        self::assertSame($this->describe($xml), $this->describe($attribute));
        $indexes = array_keys($xml->getIndexes());
        sort($indexes);
        self::assertSame(['idx_nowo_api_token_expires', 'idx_nowo_api_token_owner', 'primary', 'uniq_nowo_api_token_legacy', 'uniq_nowo_api_token_lookup', 'uniq_nowo_api_token_owner_name'], $indexes);
    }

    public function testCollationsFollowThePlatform(): void
    {
        $table = $this->table(['doctrine' => ['table_prefix' => '']], ApiToken::class);
        self::assertSame('api_token', $table->getName());

        $collation = $table->getColumn('owner_id')->getPlatformOptions()['collation'] ?? null;
        if ($this->platformIsMySql) {
            self::assertSame('utf8mb4_bin', $collation);
            self::assertSame('ascii_bin', $table->getColumn('lookup_id')->getPlatformOptions()['collation'] ?? null);
            self::assertSame('ascii_bin', $table->getColumn('id')->getPlatformOptions()['collation'] ?? null);
        } else {
            self::assertNull($collation, 'PostgreSQL and SQLite compare exactly by default');
        }
    }

    private bool $platformIsMySql = false;

    /**
     * @param array<string, mixed> $override
     * @param class-string $class
     */
    private function table(array $override, string $class): Table
    {
        $host     = Host::standard();
        $kernel   = $this->bootKernel($host->bundleConfig($override), Host::databaseUrl(), $host->configure());
        $doctrine = $kernel->getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $em = $doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->platformIsMySql = $em->getConnection()->getDatabasePlatform() instanceof AbstractMySQLPlatform;
        $schema                = (new SchemaTool($em))->getSchemaFromMetadata([$em->getClassMetadata($class)]);
        foreach ($schema->getTables() as $table) {
            return $table;
        }
        self::fail('no table');
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(Table $table): array
    {
        $columns = [];
        foreach ($table->getColumns() as $column) {
            $columns[$column->getName()] = [$column->getType()::class, $column->getLength(), $column->getNotnull(), $column->getPlatformOptions()];
        }
        ksort($columns);
        $indexes = [];
        foreach ($table->getIndexes() as $name => $index) {
            $indexes[$name] = [$index->getColumns(), $index->isUnique(), $index->isPrimary()];
        }
        ksort($indexes);

        return ['columns' => $columns, 'indexes' => $indexes];
    }
}
