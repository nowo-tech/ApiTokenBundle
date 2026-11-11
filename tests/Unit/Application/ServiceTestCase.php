<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Unit\Application;

use Nowo\ApiTokenBundle\Application\Default\ConfigApiTokenPolicy;
use Nowo\ApiTokenBundle\Application\Default\ConfigSettingsProvider;
use Nowo\ApiTokenBundle\Application\Default\NoReachChoices;
use Nowo\ApiTokenBundle\Application\Service\ApiTokenAuthenticator;
use Nowo\ApiTokenBundle\Application\Service\ApiTokenIssuer;
use Nowo\ApiTokenBundle\Application\Service\ApiTokenRevoker;
use Nowo\ApiTokenBundle\Application\Token\TokenCodec;
use Nowo\ApiTokenBundle\Contract\ApiTokenPolicyInterface;
use Nowo\ApiTokenBundle\Contract\ApiTokenSettingsProviderInterface;
use Nowo\ApiTokenBundle\Contract\Grants;
use Nowo\ApiTokenBundle\Contract\IssuedToken;
use Nowo\ApiTokenBundle\Contract\IssueRequest;
use Nowo\ApiTokenBundle\Contract\ModuleDefinition;
use Nowo\ApiTokenBundle\Contract\ModuleRegistry;
use Nowo\ApiTokenBundle\Contract\OwnerRef;
use Nowo\ApiTokenBundle\Contract\OwnerResolverInterface;
use Nowo\ApiTokenBundle\Contract\OwnerStatusInterface;
use Nowo\ApiTokenBundle\Contract\Reach;
use Nowo\ApiTokenBundle\Contract\ReachChoice;
use Nowo\ApiTokenBundle\Contract\ReachProviderInterface;
use Nowo\ApiTokenBundle\Tests\Fixtures\InMemoryApiTokenStore;
use Nowo\ApiTokenBundle\Tests\Fixtures\SpyHasher;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use stdClass;
use Symfony\Component\Clock\MockClock;

/**
 * Wires the application services on an in-memory store, a mock clock and a recording dispatcher.
 */
abstract class ServiceTestCase extends TestCase
{
    protected InMemoryApiTokenStore $store;

    protected SpyHasher $hasher;

    protected MockClock $clock;

    protected TokenCodec $codec;

    /** @var list<object> */
    protected array $events = [];

    /** @var array<string, object> owner key => user */
    protected array $users = [];

    /** @var array<string, bool> owner key => inactive */
    protected array $inactive = [];

    protected function setUp(): void
    {
        $this->store  = new InMemoryApiTokenStore();
        $this->hasher = new SpyHasher();
        $this->clock  = new MockClock('2026-11-12 10:00:00', 'UTC');
        $this->codec  = new TokenCodec('nwt_');
        $this->events = [];
    }

    protected function registry(): ModuleRegistry
    {
        return new ModuleRegistry([
            new ModuleDefinition('tasks', ['my_tasks', 'read', 'tasks', 'full']),
            new ModuleDefinition('wiki', ['read', 'edit', 'full']),
            new ModuleDefinition('mcp', ['on'], ['destructive'], '', null, ['tasks', 'wiki']),
        ]);
    }

    protected function dispatcher(): EventDispatcherInterface
    {
        return new class($this) implements EventDispatcherInterface {
            public function __construct(private readonly ServiceTestCase $test)
            {
            }

            public function dispatch(object $event): object
            {
                $this->test->record($event);

                return $event;
            }
        };
    }

    public function record(object $event): void
    {
        $this->events[] = $event;
    }

    /**
     * @param array<string, mixed> $options
     */
    protected function issuer(array $options = []): ApiTokenIssuer
    {
        $registry = $this->registry();

        return new ApiTokenIssuer(
            $this->store,
            $this->codec,
            $this->hasher,
            $registry,
            $options['policy'] ?? new ConfigApiTokenPolicy($registry, 365),
            $options['reach'] ?? new NoReachChoices(),
            $options['settings'] ?? new ConfigSettingsProvider(),
            $this->dispatcher(),
            $options['maxPerOwner'] ?? 20,
            $options['maxDays'] ?? 365,
            $options['allowEverything'] ?? true,
            $options['maxReachEntries'] ?? 200,
            $this->clock,
        );
    }

    protected function authenticator(?ApiTokenSettingsProviderInterface $settings = null): ApiTokenAuthenticator
    {
        $test = $this;

        return new ApiTokenAuthenticator(
            $this->store,
            $this->codec,
            $this->hasher,
            $this->registry(),
            $settings ?? new ConfigSettingsProvider(),
            new class($test) implements OwnerResolverInterface {
                public function __construct(private readonly ServiceTestCase $test)
                {
                }

                public function ownerOf(object $user): OwnerRef
                {
                    return new OwnerRef('user', 'x');
                }

                public function userOf(OwnerRef $owner): ?object
                {
                    return $this->test->userOf($owner);
                }
            },
            new class($test) implements OwnerStatusInterface {
                public function __construct(private readonly ServiceTestCase $test)
                {
                }

                public function isActive(object $user): bool
                {
                    return $this->test->isActive($user);
                }
            },
            $this->dispatcher(),
            $this->clock,
        );
    }

    protected function revoker(): ApiTokenRevoker
    {
        return new ApiTokenRevoker($this->store, [], $this->dispatcher());
    }

    public function userOf(OwnerRef $owner): ?object
    {
        return $this->users[$owner->key()] ?? null;
    }

    public function isActive(object $user): bool
    {
        foreach ($this->users as $key => $known) {
            if ($known === $user) {
                return !($this->inactive[$key] ?? false);
            }
        }

        return false;
    }

    protected function addUser(string $id): OwnerRef
    {
        $owner                      = new OwnerRef('user', $id);
        $this->users[$owner->key()] = new stdClass();

        return $owner;
    }

    /**
     * @param array<string, string> $levels
     */
    protected function issue(OwnerRef $owner, string $name = 'CI', array $levels = ['tasks' => 'read'], int $days = 30, ?Reach $reach = null): IssuedToken
    {
        return $this->issuer()->issue(new IssueRequest($owner, $name, new Grants($levels), $reach ?? Reach::everything(), $days));
    }

    /**
     * Forgets the dispatched events (a method, so static analysis does not narrow the list to empty).
     */
    protected function clearEvents(): void
    {
        $this->events = [];
    }

    /**
     * The dispatched events so far.
     *
     * @return list<object>
     */
    protected function events(): array
    {
        return $this->events;
    }

    /**
     * @param list<string>|null $modules
     */
    protected static function policy(bool $mayIssue = true, ?array $modules = null, int $maxDays = 365): ApiTokenPolicyInterface
    {
        return new class($mayIssue, $modules, $maxDays) implements ApiTokenPolicyInterface {
            /**
             * @param list<string>|null $modules
             */
            public function __construct(private readonly bool $mayIssue, private readonly ?array $modules, private readonly int $maxDays)
            {
            }

            public function mayIssue(OwnerRef $owner): bool
            {
                return $this->mayIssue;
            }

            public function modulesFor(OwnerRef $owner): array
            {
                return $this->modules ?? ['tasks', 'wiki', 'mcp'];
            }

            public function maxTtlDays(OwnerRef $owner): int
            {
                return $this->maxDays;
            }
        };
    }

    /**
     * @param list<string> $keys
     */
    protected static function reachChoices(array $keys): ReachProviderInterface
    {
        return new class($keys) implements ReachProviderInterface {
            /**
             * @param list<string> $keys
             */
            public function __construct(private readonly array $keys)
            {
            }

            public function choicesFor(OwnerRef $owner): array
            {
                $choices = [];
                foreach ($this->keys as $key) {
                    $choices[] = new ReachChoice($key, $key);
                }

                return $choices;
            }
        };
    }
}
