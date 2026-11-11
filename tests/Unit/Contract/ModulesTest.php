<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Unit\Contract;

use Closure;
use Nowo\ApiTokenBundle\Contract\Exception\InvalidApiTokenArgumentException;
use Nowo\ApiTokenBundle\Contract\Grants;
use Nowo\ApiTokenBundle\Contract\ModuleDefinition;
use Nowo\ApiTokenBundle\Contract\ModuleLevelMode;
use Nowo\ApiTokenBundle\Contract\ModuleRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModulesTest extends TestCase
{
    private function registry(): ModuleRegistry
    {
        return new ModuleRegistry([
            new ModuleDefinition('tasks', ['my_tasks', 'read', 'tasks', 'full'], [], 'module.tasks', 'tasks'),
            new ModuleDefinition('wiki', ['read', 'edit', 'full'], [], '', 'edit'),
            new ModuleDefinition('mcp', ['on'], ['destructive'], '', null, ['tasks', 'wiki']),
            new ModuleDefinition('agent', ['on'], [], '', null, ['mcp']),
        ]);
    }

    public function testDefinition(): void
    {
        $tasks = $this->registry()->get('tasks');
        self::assertNotNull($tasks);
        self::assertSame(ModuleLevelMode::Ordered, $tasks->mode());
        self::assertSame(ModuleLevelMode::Flags, $this->registry()->get('mcp')?->mode());
        self::assertSame(1, $tasks->rank('read'));
        self::assertNull($tasks->rank('admin'));
        self::assertTrue($tasks->isWriteLevel('full'));
        self::assertTrue($tasks->isWriteLevel('tasks'));
        self::assertFalse($tasks->isWriteLevel('read'));
        self::assertFalse($tasks->isWriteLevel('nope'));
        $mcp = $this->registry()->get('mcp');
        self::assertNotNull($mcp);
        self::assertFalse($mcp->isWriteLevel('on'));
        self::assertTrue($mcp->hasFlag('destructive'));
        self::assertSame('module.tasks', $tasks->labelKey);
    }

    /**
     * @return iterable<string, array{Closure(): ModuleDefinition}>
     */
    public static function invalidDefinitions(): iterable
    {
        yield 'bad key' => [static fn (): ModuleDefinition => new ModuleDefinition('Tasks', ['read'])];
        yield 'no level' => [static fn (): ModuleDefinition => new ModuleDefinition('tasks', [])];
        yield 'bad level' => [static fn (): ModuleDefinition => new ModuleDefinition('tasks', ['Read'])];
        yield 'bad flag' => [static fn (): ModuleDefinition => new ModuleDefinition('tasks', ['read'], ['x y'])];
        yield 'repeated level' => [static fn (): ModuleDefinition => new ModuleDefinition('tasks', ['read', 'read'])];
        yield 'repeated flag' => [static fn (): ModuleDefinition => new ModuleDefinition('tasks', ['read'], ['a', 'a'])];
        yield 'unknown write level' => [static fn (): ModuleDefinition => new ModuleDefinition('tasks', ['read'], [], '', 'write')];
        yield 'requires itself' => [static fn (): ModuleDefinition => new ModuleDefinition('tasks', ['read'], [], '', null, ['tasks'])];
        yield 'non-string level' => [static fn (): ModuleDefinition => new ModuleDefinition('tasks', self::untyped([1]))];
    }

    /**
     * @param Closure(): ModuleDefinition $build
     */
    #[DataProvider('invalidDefinitions')]
    public function testInvalidDefinitions(Closure $build): void
    {
        $this->expectException(InvalidApiTokenArgumentException::class);
        $build();
    }

    public function testRegistryRefusesDuplicatesAndUnknownRequirements(): void
    {
        try {
            new ModuleRegistry([new ModuleDefinition('a', ['on']), new ModuleDefinition('a', ['on'])]);
            self::fail('duplicate accepted');
        } catch (InvalidApiTokenArgumentException) {
        }
        $this->expectException(InvalidApiTokenArgumentException::class);
        new ModuleRegistry([new ModuleDefinition('a', ['on'], [], '', null, ['b'])]);
    }

    public function testRegistryLookups(): void
    {
        $registry = $this->registry();
        self::assertSame(['tasks', 'wiki', 'mcp', 'agent'], $registry->keys());
        self::assertCount(4, $registry->all());
        self::assertNull($registry->get('nope'));
    }

    public function testAtLeastNeverCoerces(): void
    {
        $registry = $this->registry();
        $grants   = new Grants(['tasks' => 'tasks', 'wiki' => 'renamed']);

        self::assertTrue($grants->atLeast('tasks', 'read', $registry));
        self::assertTrue($grants->atLeast('tasks', 'tasks', $registry));
        self::assertFalse($grants->atLeast('tasks', 'full', $registry));
        self::assertFalse($grants->atLeast('tasks', 'unknown', $registry));
        self::assertFalse($grants->atLeast('wiki', 'read', $registry), 'an unknown stored level is no access');
        self::assertFalse($grants->atLeast('mcp', 'on', $registry));
        self::assertFalse($grants->atLeast('ghost', 'on', $registry));
    }

    public function testNarrowDropsUnknownAndUnmetAndDisallowed(): void
    {
        $registry = $this->registry();
        $stored   = new Grants(
            ['tasks' => 'read', 'wiki' => 'renamed', 'ghost' => 'on', 'mcp' => 'on', 'agent' => 'on'],
            ['mcp' => ['destructive', 'unknown'], 'tasks' => ['nope'], 'ghost' => ['x'], 'wiki' => ['x']],
        );

        $narrowed = $registry->narrow($stored);
        self::assertSame(['agent' => 'on', 'mcp' => 'on', 'tasks' => 'read'], $narrowed->levels);
        self::assertSame(['mcp' => ['destructive']], $narrowed->flags);

        $withoutTasks = $registry->narrow($stored, ['wiki', 'mcp', 'agent']);
        self::assertSame([], $withoutTasks->levels, 'mcp loses its required module, then agent loses mcp');
    }

    public function testAssertValid(): void
    {
        $registry = $this->registry();
        $registry->assertValid(new Grants(['tasks' => 'full', 'mcp' => 'on'], ['mcp' => ['destructive']]));
        $registry->assertValid(new Grants(['wiki' => 'read']), ['wiki']);
        $this->addToAssertionCount(2);
    }

    /**
     * @return iterable<string, array{Grants, list<string>|null}>
     */
    public static function invalidGrants(): iterable
    {
        yield 'empty' => [new Grants([]), null];
        yield 'unknown module' => [new Grants(['ghost' => 'on']), null];
        yield 'not allowed' => [new Grants(['tasks' => 'read']), ['wiki']];
        yield 'unknown level' => [new Grants(['tasks' => 'admin']), null];
        yield 'flag without level' => [new Grants(['tasks' => 'read'], ['mcp' => ['destructive']]), null];
        yield 'unknown flag' => [new Grants(['mcp' => 'on', 'tasks' => 'read'], ['mcp' => ['nuke']]), null];
        yield 'requirement unmet' => [new Grants(['mcp' => 'on']), null];
    }

    /**
     * @param list<string>|null $allowed
     */
    #[DataProvider('invalidGrants')]
    public function testAssertValidRefuses(Grants $grants, ?array $allowed): void
    {
        $this->expectException(InvalidApiTokenArgumentException::class);
        $this->registry()->assertValid($grants, $allowed);
    }

    /**
     * Hands a deliberately ill-typed value to a typed constructor without silencing the analyser.
     */
    private static function untyped(mixed $value): mixed
    {
        return $value;
    }
}
