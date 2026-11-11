<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Contract;

use Nowo\ApiTokenBundle\Contract\Exception\InvalidApiTokenArgumentException;

use function array_unique;
use function array_values;
use function count;
use function in_array;
use function is_string;
use function preg_match;
use function sprintf;

/**
 * A module a token can be granted (contract §6.4): its key, its levels ordered low → high, its independent flags, the
 * translation key of its label, the first level that counts as a write and the modules of which at least one must be
 * granted too (e.g. `mcp` requires `tasks` or `wiki`).
 */
final readonly class ModuleDefinition
{
    /** Module keys, levels and flags. */
    public const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/D';

    /** @var list<string> */
    public array $levels;

    /** @var list<string> */
    public array $flags;

    /** @var list<string> */
    public array $requiresModules;

    /**
     * @param list<string> $levels ordered low → high, e.g. `['my_tasks', 'read', 'tasks', 'full']`
     * @param list<string> $flags e.g. `['destructive']`
     * @param list<string> $requiresModules at least one of these modules must be granted as well
     *
     * @throws InvalidApiTokenArgumentException when a name is invalid, a level or flag repeats, or the first write
     *                                          level is not one of the levels
     */
    public function __construct(
        public string $key,
        array $levels,
        array $flags = [],
        public string $labelKey = '',
        public ?string $firstWriteLevel = null,
        array $requiresModules = [],
    ) {
        $this->assertName($key, 'module key');
        if ($levels === []) {
            throw new InvalidApiTokenArgumentException(sprintf('Module "%s" needs at least one level.', $key));
        }
        foreach ([...$levels, ...$flags, ...$requiresModules] as $name) {
            $this->assertName($name, sprintf('level, flag or required module of "%s"', $key));
        }
        if (count(array_unique($levels)) !== count($levels) || count(array_unique($flags)) !== count($flags)) {
            throw new InvalidApiTokenArgumentException(sprintf('Module "%s" repeats a level or a flag.', $key));
        }
        if ($firstWriteLevel !== null && !in_array($firstWriteLevel, $levels, true)) {
            throw new InvalidApiTokenArgumentException(sprintf('Module "%s": the first write level is not one of its levels.', $key));
        }
        if (in_array($key, $requiresModules, true)) {
            throw new InvalidApiTokenArgumentException(sprintf('Module "%s" cannot require itself.', $key));
        }
        $this->levels          = array_values($levels);
        $this->flags           = array_values($flags);
        $this->requiresModules = array_values(array_unique($requiresModules));
    }

    /**
     * Ordered for a ladder of two or more levels, Flags for a single on/off level.
     */
    public function mode(): ModuleLevelMode
    {
        return count($this->levels) > 1 ? ModuleLevelMode::Ordered : ModuleLevelMode::Flags;
    }

    /**
     * Position of a level on the ladder (0 = lowest), null when the module has no such level.
     */
    public function rank(string $level): ?int
    {
        foreach ($this->levels as $rank => $name) {
            if ($name === $level) {
                return $rank;
            }
        }

        return null;
    }

    /**
     * Whether the module has this flag.
     */
    public function hasFlag(string $flag): bool
    {
        return in_array($flag, $this->flags, true);
    }

    /**
     * Whether a level counts in the write budget (from {@see self::$firstWriteLevel} up). False when unknown.
     */
    public function isWriteLevel(string $level): bool
    {
        $rank = $this->rank($level);
        if ($rank === null || $this->firstWriteLevel === null) {
            return false;
        }

        return $rank >= (int) $this->rank($this->firstWriteLevel);
    }

    private function assertName(mixed $name, string $what): void
    {
        if (!is_string($name) || preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidApiTokenArgumentException(sprintf('Invalid %s: expected ^[a-z][a-z0-9_]{0,31}$.', $what));
        }
    }
}
