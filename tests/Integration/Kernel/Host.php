<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Integration\Kernel;

use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ApiTokenBundle\Contract\OwnerRef;
use Nowo\ApiTokenBundle\Tests\Fixtures\Ddd\Member;
use Nowo\ApiTokenBundle\Tests\Fixtures\Ddd\MemberOwnerResolver;
use Nowo\ApiTokenBundle\Tests\Fixtures\Ddd\MemberStatus;
use Nowo\ApiTokenBundle\Tests\Fixtures\QueryLog;
use Nowo\ApiTokenBundle\Tests\Fixtures\RecordingListener;
use Nowo\ApiTokenBundle\Tests\Fixtures\Standard\ActiveUserChecker;
use Nowo\ApiTokenBundle\Tests\Fixtures\Standard\User;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Filesystem\Filesystem;

use function array_replace_recursive;
use function assert;
use function dirname;
use function getenv;
use function is_string;

/**
 * The two host kinds of the functional suite (architecture bar): a standard Symfony app (attribute `User` entity,
 * default entity manager, the bundle's Doctrine owner resolver and the host's `security.user_checker`) and a DDD app
 * (plain XML-mapped `Member` on a second entity manager `ddd`, `auto_mapping: false` with the bundle's XML registered
 * by the host, its own owner resolver and status ports, another table prefix).
 */
final readonly class Host
{
    public const STANDARD = 'standard';

    public const DDD = 'ddd';

    /** Fixed start time of the test clock. */
    public const NOW = '2026-11-12 10:00:00';

    private function __construct(public string $kind)
    {
    }

    public static function standard(): self
    {
        return new self(self::STANDARD);
    }

    public static function ddd(): self
    {
        return new self(self::DDD);
    }

    /**
     * `NOWO_TEST_DATABASE_URL` (CI matrix: MySQL, PostgreSQL) or a SQLite file.
     */
    public static function databaseUrl(): string
    {
        $url = getenv('NOWO_TEST_DATABASE_URL');
        if (is_string($url) && $url !== '') {
            return $url;
        }
        (new Filesystem())->mkdir(TestKernel::baseDir());

        return 'sqlite:///' . TestKernel::baseDir() . '/functional.sqlite';
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    public function bundleConfig(array $override = []): array
    {
        $common = [
            'enabled' => true,
            'modules' => [
                'tasks' => ['levels' => ['my_tasks', 'read', 'tasks', 'full'], 'label' => 'Tasks'],
                'wiki'  => ['levels' => ['read', 'edit', 'full']],
                'mcp'   => ['levels' => ['on'], 'flags' => ['destructive']],
            ],
        ];
        $kind = $this->kind === self::STANDARD
            ? ['owner' => ['class' => User::class]]
            : [
                'doctrine' => ['entity_manager' => 'ddd', 'auto_mapping' => false, 'table_prefix' => 'ddd_'],
                'owner'    => ['type' => 'member', 'resolver' => MemberOwnerResolver::class, 'status' => MemberStatus::class],
            ];

        return array_replace_recursive($common, $kind, $override);
    }

    /**
     * @return Closure(ContainerConfigurator, string): void
     */
    public function configure(): Closure
    {
        $kind = $this->kind;

        return static function (ContainerConfigurator $container, string $url) use ($kind): void {
            $root = dirname(__DIR__, 3);
            if ($kind === self::STANDARD) {
                $container->extension('doctrine', [
                    'dbal' => ['url' => $url],
                    'orm'  => ['mappings' => ['Fixtures' => ['type' => 'attribute', 'dir' => $root . '/tests/Fixtures/Standard', 'prefix' => 'Nowo\ApiTokenBundle\Tests\Fixtures\Standard', 'is_bundle' => false]]],
                ]);
                $container->services()->set('security.user_checker', ActiveUserChecker::class);
            } else {
                $container->extension('doctrine', [
                    'dbal' => ['default_connection' => 'default', 'connections' => ['default' => ['url' => $url], 'ddd' => ['url' => $url]]],
                    'orm'  => [
                        'default_entity_manager' => 'default',
                        'entity_managers'        => [
                            'default' => ['connection' => 'default'],
                            'ddd'     => ['connection' => 'ddd', 'mappings' => [
                                'Members'  => ['type' => 'xml', 'dir' => $root . '/tests/Fixtures/Ddd/mapping', 'prefix' => 'Nowo\ApiTokenBundle\Tests\Fixtures\Ddd', 'is_bundle' => false],
                                'ApiToken' => ['type' => 'xml', 'dir' => $root . '/src/Resources/config/doctrine-mapping', 'prefix' => 'Nowo\ApiTokenBundle\Domain', 'is_bundle' => false],
                            ]],
                        ],
                    ],
                ]);
                $container->services()->set(MemberOwnerResolver::class)->autowire();
                $container->services()->set(MemberStatus::class);
            }
            $container->services()->set('clock', MockClock::class)->args([self::NOW, 'UTC'])->public();
            $container->services()->set(RecordingListener::class)->public()
                ->tag('nowo_api_token.revocation_listener')
                ->tag('kernel.event_listener', ['event' => 'Nowo\ApiTokenBundle\Contract\Event\ApiTokenIssuedEvent', 'method' => 'onEvent'])
                ->tag('kernel.event_listener', ['event' => 'Nowo\ApiTokenBundle\Contract\Event\ApiTokenRevokedEvent', 'method' => 'onEvent'])
                ->tag('kernel.event_listener', ['event' => 'Nowo\ApiTokenBundle\Contract\Event\ApiTokenUsedEvent', 'method' => 'onEvent']);
            $container->services()->set(QueryLog::class)->public()->tag('doctrine.middleware');
        };
    }

    public function entityManagerName(): string
    {
        return $this->kind === self::STANDARD ? 'default' : 'ddd';
    }

    public function tablePrefix(): string
    {
        return $this->kind === self::STANDARD ? 'nowo_' : 'ddd_';
    }

    public function entityManager(TestKernel $kernel): EntityManagerInterface
    {
        $doctrine = $kernel->getContainer()->get('doctrine');
        assert($doctrine instanceof ManagerRegistry);
        $em = $doctrine->getManager($this->entityManagerName());
        assert($em instanceof EntityManagerInterface);

        return $em;
    }

    /**
     * Drops and creates the host tables and the token table on the configured database.
     */
    public function resetSchema(TestKernel $kernel): void
    {
        $em       = $this->entityManager($kernel);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool     = new SchemaTool($em);
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    public function owner(string $id): OwnerRef
    {
        return new OwnerRef($this->kind === self::STANDARD ? 'user' : 'member', $id);
    }

    /**
     * @param non-empty-string $id
     */
    public function createUser(TestKernel $kernel, string $id): object
    {
        $em   = $this->entityManager($kernel);
        $user = $this->kind === self::STANDARD ? new User($id) : new Member($id);
        $em->persist($user);
        $em->flush();

        return $user;
    }

    public function deactivate(TestKernel $kernel, string $id): void
    {
        $em   = $this->entityManager($kernel);
        $user = $em->find($this->kind === self::STANDARD ? User::class : Member::class, $id);
        if ($user instanceof User) {
            $user->active = false;
        } elseif ($user instanceof Member) {
            $user->suspend();
        }
        $em->flush();
    }

    public function deleteUser(TestKernel $kernel, string $id): void
    {
        $em   = $this->entityManager($kernel);
        $user = $em->find($this->kind === self::STANDARD ? User::class : Member::class, $id);
        assert($user !== null);
        $em->remove($user);
        $em->flush();
        $em->clear();
    }
}
