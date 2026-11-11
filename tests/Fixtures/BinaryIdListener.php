<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Fixtures;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Nowo\ApiTokenBundle\Tests\Fixtures\Ddd\Member;
use Nowo\ApiTokenBundle\Tests\Fixtures\Standard\User;

use function in_array;

/**
 * Host fixture: on MySQL the hosts' user ids compare exactly (binary collation), so `UserA` and `usera` can both
 * exist, as they do on SQLite and PostgreSQL.
 */
final class BinaryIdListener
{
    public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
    {
        $metadata = $args->getClassMetadata();
        if (!in_array($metadata->getName(), [User::class, Member::class], true)
            || !$args->getObjectManager()->getConnection()->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            return;
        }
        $field                                    = $metadata->getSingleIdentifierFieldName();
        $metadata->fieldMappings[$field]->options = ['collation' => 'utf8mb4_bin'];
    }
}
