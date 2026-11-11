<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Contract;

use Nowo\ApiTokenBundle\Contract\Exception\InvalidApiTokenArgumentException;

use function array_unique;
use function array_values;
use function in_array;
use function is_array;
use function is_string;
use function ksort;

use const SORT_STRING;

/**
 * What a token may do, per module: at most one level per module plus ticked flags. No grant = no access to the module.
 *
 * The value object checks shape only; {@see ModuleRegistry} checks that the modules, levels and flags exist.
 */
final readonly class Grants
{
    /** @var array<string, string> */
    public array $levels;

    /** @var array<string, list<string>> */
    public array $flags;

    /**
     * Levels and flags are kept in module-key order, so a stored and re-read value is identical on every database
     * (MySQL reorders JSON object keys).
     *
     * @param array<string, string> $levels module => level
     * @param array<string, list<string>> $flags module => ticked flags
     *
     * @throws InvalidApiTokenArgumentException when a key or value is not a string
     */
    public function __construct(array $levels, array $flags = [])
    {
        $normalisedLevels = [];
        foreach ($levels as $module => $level) {
            if (!is_string($module) || !is_string($level)) {
                throw new InvalidApiTokenArgumentException('Grants levels must map module keys to level names.');
            }
            $normalisedLevels[$module] = $level;
        }
        $normalisedFlags = [];
        foreach ($flags as $module => $moduleFlags) {
            if (!is_string($module) || !is_array($moduleFlags)) {
                throw new InvalidApiTokenArgumentException('Grants flags must map module keys to lists of flag names.');
            }
            foreach ($moduleFlags as $flag) {
                if (!is_string($flag)) {
                    throw new InvalidApiTokenArgumentException('Grants flags must map module keys to lists of flag names.');
                }
            }
            if ($moduleFlags !== []) {
                $normalisedFlags[$module] = array_values(array_unique($moduleFlags));
            }
        }
        ksort($normalisedLevels, SORT_STRING);
        ksort($normalisedFlags, SORT_STRING);
        $this->levels = $normalisedLevels;
        $this->flags  = $normalisedFlags;
    }

    /**
     * Tolerant reader of stored JSON (`{"levels": {…}, "flags": {…}}`): anything that is not a string pair is dropped,
     * so a damaged row narrows to fewer grants, never more.
     */
    public static function fromStored(mixed $data): self
    {
        $levels = [];
        $flags  = [];
        if (is_array($data)) {
            foreach (is_array($data['levels'] ?? null) ? $data['levels'] : [] as $module => $level) {
                if (is_string($module) && is_string($level)) {
                    $levels[$module] = $level;
                }
            }
            foreach (is_array($data['flags'] ?? null) ? $data['flags'] : [] as $module => $moduleFlags) {
                if (!is_string($module) || !is_array($moduleFlags)) {
                    continue;
                }
                $kept = [];
                foreach ($moduleFlags as $flag) {
                    if (is_string($flag)) {
                        $kept[] = $flag;
                    }
                }
                $flags[$module] = $kept;
            }
        }

        return new self($levels, $flags);
    }

    /**
     * The stored shape `{"levels": {…}, "flags": {…}}`.
     *
     * @return array{levels: array<string, string>, flags: array<string, list<string>>}
     */
    public function toArray(): array
    {
        return ['levels' => $this->levels, 'flags' => $this->flags];
    }

    /**
     * The granted level of a module; null = no access to the module.
     */
    public function level(string $module): ?string
    {
        return $this->levels[$module] ?? null;
    }

    /**
     * Whether the module is granted at this level or higher. False when the module, the level or the granted level is
     * unknown to the registry (never coerced).
     */
    public function atLeast(string $module, string $level, ModuleRegistry $registry): bool
    {
        $granted    = $this->levels[$module] ?? null;
        $definition = $registry->get($module);
        if ($granted === null || !$definition instanceof ModuleDefinition) {
            return false;
        }
        $grantedRank  = $definition->rank($granted);
        $requiredRank = $definition->rank($level);

        return $grantedRank !== null && $requiredRank !== null && $grantedRank >= $requiredRank;
    }

    /**
     * Whether the flag is ticked for the module (and the module is granted).
     */
    public function hasFlag(string $module, string $flag): bool
    {
        return isset($this->levels[$module]) && in_array($flag, $this->flags[$module] ?? [], true);
    }

    /**
     * Whether no module is granted.
     */
    public function isEmpty(): bool
    {
        return $this->levels === [];
    }
}
