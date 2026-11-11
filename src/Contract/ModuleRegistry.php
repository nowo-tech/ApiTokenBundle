<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Contract;

use Nowo\ApiTokenBundle\Contract\Exception\InvalidApiTokenArgumentException;

use function array_keys;
use function in_array;
use function sprintf;

/**
 * The modules tokens can be granted: the host's `modules:` configuration plus the module providers of the bridges,
 * built once per container (immutable, the same for every request in worker mode).
 *
 * {@see self::narrow()} is the read-time rule (unknown grants are dropped, never widened); {@see self::assertValid()}
 * is the issue-time rule (unknown grants are refused).
 */
final readonly class ModuleRegistry
{
    /** @var array<string, ModuleDefinition> */
    private array $modules;

    /**
     * @param iterable<ModuleDefinition> $modules
     *
     * @throws InvalidApiTokenArgumentException when two modules share a key or a required module is unknown
     */
    public function __construct(iterable $modules)
    {
        $byKey = [];
        foreach ($modules as $module) {
            if (isset($byKey[$module->key])) {
                throw new InvalidApiTokenArgumentException(sprintf('Module "%s" is declared twice.', $module->key));
            }
            $byKey[$module->key] = $module;
        }
        foreach ($byKey as $module) {
            foreach ($module->requiresModules as $required) {
                if (!isset($byKey[$required])) {
                    throw new InvalidApiTokenArgumentException(sprintf('Module "%s" requires the unknown module "%s".', $module->key, $required));
                }
            }
        }
        $this->modules = $byKey;
    }

    /**
     * The module of this key, or null.
     */
    public function get(string $key): ?ModuleDefinition
    {
        return $this->modules[$key] ?? null;
    }

    /**
     * Every module, keyed by module key, in declaration order.
     *
     * @return array<string, ModuleDefinition>
     */
    public function all(): array
    {
        return $this->modules;
    }

    /**
     * Every module key.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->modules);
    }

    /**
     * Read-time rule: keeps only the grants the registry knows, within the allowed modules (null = all). An unknown
     * module, level or flag is dropped, and so is a module whose required modules are not granted. Never widens.
     *
     * @param list<string>|null $allowedModules
     */
    public function narrow(Grants $grants, ?array $allowedModules = null): Grants
    {
        $levels = [];
        foreach ($grants->levels as $module => $level) {
            $definition = $this->modules[$module] ?? null;
            if ($definition === null || $definition->rank($level) === null) {
                continue;
            }
            if ($allowedModules !== null && !in_array($module, $allowedModules, true)) {
                continue;
            }
            $levels[$module] = $level;
        }
        $levels = $this->withRequirementsMet($levels);

        $flags = [];
        foreach ($grants->flags as $module => $moduleFlags) {
            if (!isset($levels[$module])) {
                continue;
            }
            $kept = [];
            foreach ($moduleFlags as $flag) {
                if ($this->modules[$module]->hasFlag($flag)) {
                    $kept[] = $flag;
                }
            }
            if ($kept !== []) {
                $flags[$module] = $kept;
            }
        }

        return new Grants($levels, $flags);
    }

    /**
     * Issue-time rule: every module, level and flag must exist, be allowed (null = all) and meet its requirements,
     * and at least one module must be granted.
     *
     * @param list<string>|null $allowedModules
     *
     * @throws InvalidApiTokenArgumentException naming the first offending module
     */
    public function assertValid(Grants $grants, ?array $allowedModules = null): void
    {
        if ($grants->levels === []) {
            throw new InvalidApiTokenArgumentException('A token needs at least one module.');
        }
        foreach ($grants->levels as $module => $level) {
            $definition = $this->modules[$module] ?? null;
            if ($definition === null || ($allowedModules !== null && !in_array($module, $allowedModules, true))) {
                throw new InvalidApiTokenArgumentException(sprintf('Module "%s" is unknown or not available.', $module));
            }
            if ($definition->rank($level) === null) {
                throw new InvalidApiTokenArgumentException(sprintf('Module "%s" has no level "%s".', $module, $level));
            }
        }
        foreach ($grants->flags as $module => $moduleFlags) {
            if (!isset($grants->levels[$module])) {
                throw new InvalidApiTokenArgumentException(sprintf('Flags of module "%s" need a level of that module.', $module));
            }
            foreach ($moduleFlags as $flag) {
                if (!$this->modules[$module]->hasFlag($flag)) {
                    throw new InvalidApiTokenArgumentException(sprintf('Module "%s" has no flag "%s".', $module, $flag));
                }
            }
        }
        if ($this->withRequirementsMet($grants->levels) !== $grants->levels) {
            throw new InvalidApiTokenArgumentException('A granted module requires another module that is not granted.');
        }
    }

    /**
     * Drops the modules whose required modules are not granted, until stable (a drop can break another requirement).
     *
     * @param array<string, string> $levels
     *
     * @return array<string, string>
     */
    private function withRequirementsMet(array $levels): array
    {
        do {
            $changed = false;
            foreach ($levels as $module => $level) {
                $required = $this->modules[$module]->requiresModules;
                if ($required === []) {
                    continue;
                }
                $met = false;
                foreach ($required as $other) {
                    $met = $met || isset($levels[$other]);
                }
                if (!$met) {
                    unset($levels[$module]);
                    $changed = true;
                }
            }
        } while ($changed);

        return $levels;
    }
}
