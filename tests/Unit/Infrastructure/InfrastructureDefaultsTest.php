<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Unit\Infrastructure;

use Closure;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\Driver\SimplifiedXmlDriver;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\Mapping\ClassMetadata as PersistenceClassMetadata;
use Doctrine\Persistence\Mapping\RuntimeReflectionService;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use LogicException;
use Nowo\ApiTokenBundle\Application\ModuleRegistryFactory;
use Nowo\ApiTokenBundle\Contract\Exception\InvalidApiTokenArgumentException;
use Nowo\ApiTokenBundle\Contract\ModuleDefinition;
use Nowo\ApiTokenBundle\Contract\ModuleDefinitionProviderInterface;
use Nowo\ApiTokenBundle\Contract\OwnerRef;
use Nowo\ApiTokenBundle\Domain\ApiToken;
use Nowo\ApiTokenBundle\Infrastructure\Doctrine\ApiTokenMetadataListener;
use Nowo\ApiTokenBundle\Infrastructure\Doctrine\Entity\ApiTokenRecord;
use Nowo\ApiTokenBundle\Infrastructure\Owner\DoctrineOwnerResolver;
use Nowo\ApiTokenBundle\Infrastructure\Owner\UserCheckerOwnerStatus;
use Nowo\ApiTokenBundle\Infrastructure\Persistence\AfterCommitQueue;
use Nowo\ApiTokenBundle\Tests\Fixtures\MemoryLogger;
use Nowo\ApiTokenBundle\Tests\Fixtures\Standard\ActiveUserChecker;
use Nowo\ApiTokenBundle\Tests\Fixtures\Standard\User;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Uid\Uuid;

use function dirname;

/**
 * The infrastructure defaults outside a kernel: the MySQL collations of the metadata listener, the Doctrine owner
 * resolver's id kinds and refusals, the user-checker status without a checker, the after-commit failure log, the
 * configured modules of the registry factory and the attribute twin.
 */
final class InfrastructureDefaultsTest extends TestCase
{
    public function testTheListenerSetsBinaryCollationsOnMySql(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_mysql', 'serverVersion' => '8.4.0']);
        $metadata   = $this->metadata();
        (new ApiTokenMetadataListener('app_'))->loadClassMetadata(new LoadClassMetadataEventArgs($metadata, $this->entityManager(static fn (): Connection => $connection)));

        self::assertSame('app_api_token', $metadata->getTableName());
        self::assertArrayHasKey('uniq_app_api_token_lookup', $metadata->table['uniqueConstraints'] ?? []);
        self::assertSame(['charset' => 'ascii', 'collation' => 'ascii_bin'], $metadata->fieldMappings['lookupId']->options);
        self::assertSame(['charset' => 'ascii', 'collation' => 'ascii_bin'], $metadata->fieldMappings['id']->options);
        self::assertSame(['charset' => 'utf8mb4', 'collation' => 'utf8mb4_bin'], $metadata->fieldMappings['ownerId']->options);
        self::assertNull($metadata->fieldMappings['name']->options);
    }

    public function testTheListenerNeedsThePlatform(): void
    {
        $metadata = $this->metadata();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('server_version');
        (new ApiTokenMetadataListener('nowo_'))->loadClassMetadata(new LoadClassMetadataEventArgs($metadata, $this->entityManager(static fn (): never => throw new RuntimeException('unreachable'))));
    }

    public function testTheResolverAcceptsIntegerAndStringableIds(): void
    {
        $uuid = Uuid::fromString('01933b1c-5d2e-7a3b-8c4d-123456789abc');
        self::assertSame('7', $this->resolver(['id' => 7])->ownerOf(new User('ignored'))->id);
        self::assertSame($uuid->toRfc4122(), $this->resolver(['id' => $uuid])->ownerOf(new User('ignored'))->id);
    }

    public function testTheResolverRefusesOtherObjectsAndUnsavedUsers(): void
    {
        try {
            $this->resolver(['id' => '1'])->ownerOf(new stdClass());
            self::fail('another class must be refused');
        } catch (InvalidApiTokenArgumentException $exception) {
            self::assertStringContainsString(User::class, $exception->getMessage());
        }

        $this->expectException(InvalidApiTokenArgumentException::class);
        $this->expectExceptionMessage('no identifier');
        $this->resolver([])->ownerOf(new User('pending'));
    }

    public function testTheResolverIgnoresOtherOwnerTypes(): void
    {
        self::assertNull($this->resolver(['id' => '1'])->userOf(new OwnerRef('member', '1')));
    }

    public function testTheStatusRunsEveryCheckerAndFailsClosed(): void
    {
        self::assertTrue((new UserCheckerOwnerStatus())->isActive(new User('1', false)), 'owner.user_checker: none');
        self::assertFalse((new UserCheckerOwnerStatus([new ActiveUserChecker()]))->isActive(new stdClass()), 'not a UserInterface');
        self::assertFalse((new UserCheckerOwnerStatus([new ActiveUserChecker()]))->isActive(new User('1', false)));
        self::assertTrue((new UserCheckerOwnerStatus([new ActiveUserChecker(), new ActiveUserChecker()]))->isActive(new User('1')));
    }

    public function testTheResolverNeedsTheOrm(): void
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->createStub(ObjectManager::class));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('not mapped by a Doctrine ORM entity manager');
        (new DoctrineOwnerResolver($registry, User::class))->userOf(new OwnerRef('user', '1'));
    }

    public function testAfterCommitFailuresAreLoggedNotThrown(): void
    {
        $logger = new MemoryLogger();
        (new AfterCommitQueue($logger))->run(static fn (): never => throw new RuntimeException('listener down'));

        self::assertCount(1, $logger->records);
        self::assertSame(RuntimeException::class, $logger->records[0]['context']['class'] ?? null);
    }

    public function testTheFactoryMergesConfiguredAndProvidedModules(): void
    {
        $provider = new class implements ModuleDefinitionProviderInterface {
            public function modules(): iterable
            {
                yield new ModuleDefinition('wiki', ['read', 'edit']);
            }
        };
        $registry = ModuleRegistryFactory::create(['tasks' => ['levels' => ['read', 'full'], 'flags' => [], 'label' => 'Tasks']], [$provider]);

        self::assertSame(['tasks', 'wiki'], $registry->keys());
        self::assertSame('Tasks', $registry->get('tasks')?->labelKey);
    }

    public function testTheAttributeTwinHoldsTheColumns(): void
    {
        $now    = new DateTimeImmutable();
        $record = new ApiTokenRecord('id', 'lookup', 'hash', 1, 'user', '1', 'CI', 'ci', [], true, [], $now, $now, null, 'cli', null);

        self::assertSame('cli', $record->createdVia);
        self::assertNull($record->legacyHash);
    }

    /**
     * @return ClassMetadata<ApiToken>
     */
    private function metadata(): ClassMetadata
    {
        $metadata = new ClassMetadata(ApiToken::class);
        $metadata->initializeReflection(new RuntimeReflectionService());
        (new SimplifiedXmlDriver([dirname(__DIR__, 3) . '/src/Resources/config/doctrine-mapping' => 'Nowo\ApiTokenBundle\Domain']))->loadMetadataForClass(ApiToken::class, $metadata);

        return $metadata;
    }

    /**
     * @param Closure(): Connection $connection
     */
    private function entityManager(Closure $connection): EntityManagerInterface
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturnCallback($connection);

        return $em;
    }

    /**
     * @param array<string, mixed> $identifier
     */
    private function resolver(array $identifier): DoctrineOwnerResolver
    {
        $classMetadata = $this->createStub(PersistenceClassMetadata::class);
        $classMetadata->method('getIdentifierValues')->willReturn($identifier);
        $repository = $this->createStub(ObjectRepository::class);
        $repository->method('findOneBy')->willReturn(null);
        $manager = $this->createStub(ObjectManager::class);
        $manager->method('getClassMetadata')->willReturn($classMetadata);
        $manager->method('getRepository')->willReturn($repository);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($manager);

        return new DoctrineOwnerResolver($registry, User::class);
    }
}
