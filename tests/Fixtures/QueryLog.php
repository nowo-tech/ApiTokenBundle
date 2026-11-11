<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Fixtures;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * Test probe (DBAL middleware): records every SQL string sent to the database.
 */
final class QueryLog implements Middleware
{
    /** @var list<string> */
    public array $queries = [];

    public function wrap(Driver $driver): Driver
    {
        $log = $this;

        return new class($driver, $log) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly QueryLog $log)
            {
                parent::__construct($driver);
            }

            public function connect(array $params): Connection
            {
                $log = $this->log;

                return new class(parent::connect($params), $log) extends AbstractConnectionMiddleware {
                    public function __construct(Connection $connection, private readonly QueryLog $log)
                    {
                        parent::__construct($connection);
                    }

                    public function prepare(string $sql): Statement
                    {
                        $this->log->queries[] = $sql;

                        return parent::prepare($sql);
                    }

                    public function query(string $sql): Result
                    {
                        $this->log->queries[] = $sql;

                        return parent::query($sql);
                    }

                    public function exec(string $sql): int|string
                    {
                        $this->log->queries[] = $sql;

                        return parent::exec($sql);
                    }
                };
            }
        };
    }

    public function clear(): void
    {
        $this->queries = [];
    }
}
