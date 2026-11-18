<?php

declare(strict_types=1);

/**
 * Second database session of the race tests (REQ-RUNTIME-003): a separate PHP process with its own connection.
 *
 * Argument 1: base64 JSON {"params": DBAL connection params, "writes": [[table, data, types, criteria|null], …],
 * "hold_ms": int|null}. It opens a transaction, runs each write (an UPDATE when `criteria` is given, else an INSERT),
 * prints "locked", then commits after `hold_ms` milliseconds, or when a line arrives on stdin when `hold_ms` is null,
 * and prints "done". Lock waits are bounded (10 s) so a broken test fails instead of hanging.
 */

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

/** @var array{params: array<string, mixed>, writes: list<array{0: string, 1: array<string, mixed>, 2: array<string, string>, 3: array<string, mixed>|null}>, hold_ms: int|null} $job */
$job        = json_decode((string) base64_decode($argv[1] ?? '', true), true, 16, \JSON_THROW_ON_ERROR);
$connection = DriverManager::getConnection($job['params']); // @phpstan-ignore argument.type
$platform   = $connection->getDatabasePlatform();
if ($platform instanceof AbstractMySQLPlatform) {
    $connection->executeStatement('SET SESSION innodb_lock_wait_timeout = 10');
} elseif ($platform instanceof PostgreSQLPlatform) {
    $connection->executeStatement("SET lock_timeout = '10s'");
} elseif ($platform instanceof SQLitePlatform) {
    $connection->executeStatement('PRAGMA busy_timeout = 10000');
}

$connection->beginTransaction();
foreach ($job['writes'] as [$table, $data, $types, $criteria]) {
    if ($criteria === null) {
        $connection->insert($table, $data, $types);
    } else {
        $connection->update($table, $data, $criteria, $types);
    }
}
fwrite(\STDOUT, "locked\n");
fflush(\STDOUT);
if ($job['hold_ms'] === null) {
    fgets(\STDIN);
} else {
    usleep($job['hold_ms'] * 1000); // @phpstan-ignore frankenphp.hardening.noBlockingSleep (a separate CLI test process, never a worker)
}
$connection->commit();
fwrite(\STDOUT, "done\n");
