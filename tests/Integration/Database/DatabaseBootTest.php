<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Integration\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Nowo\ApiTokenBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\ApiTokenBundle\Tests\Integration\Kernel\TestKernel;
use Symfony\Component\Filesystem\Filesystem;

use function getenv;
use function is_string;

/**
 * The CI database matrix (SQLite, MySQL, PostgreSQL; `NOWO_TEST_DATABASE_URL`) boots the bundle next to DoctrineBundle
 * and proves the identifier-column rule (audit LQ) the bundle tables will follow from S1: an owner id column compares
 * exactly (`usera` never matches `UserA`), with `utf8mb4_bin` on MySQL / MariaDB and the default elsewhere.
 */
final class DatabaseBootTest extends KernelTestCase
{
    public function testOwnerIdColumnsCompareExactlyOnTheConfiguredDatabase(): void
    {
        $connection = $this->connection();
        $collation  = $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform ? ' CHARACTER SET utf8mb4 COLLATE utf8mb4_bin' : '';

        $connection->executeStatement('DROP TABLE IF EXISTS s0_owner_probe');
        $connection->executeStatement('CREATE TABLE s0_owner_probe (owner_id VARCHAR(128)' . $collation . ' NOT NULL)');
        $connection->insert('s0_owner_probe', ['owner_id' => 'UserA']);

        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM s0_owner_probe WHERE owner_id = ?', ['usera']));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM s0_owner_probe WHERE owner_id = ?', ['UserA']));

        $connection->executeStatement('DROP TABLE s0_owner_probe');
    }

    private function connection(): Connection
    {
        $url = getenv('NOWO_TEST_DATABASE_URL');
        if (!is_string($url) || $url === '') {
            (new Filesystem())->mkdir(TestKernel::baseDir());
            $url = 'sqlite:///' . TestKernel::baseDir() . '/probe.sqlite';
        }
        $connection = $this->bootKernel([], $url)->getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
