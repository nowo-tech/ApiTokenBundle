<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Application;

use Nowo\ApiTokenBundle\Contract\ModuleDefinition;
use Nowo\ApiTokenBundle\Contract\ModuleDefinitionProviderInterface;
use Nowo\ApiTokenBundle\Contract\ModuleRegistry;

/**
 * Builds the {@see ModuleRegistry} service from the `modules:` configuration and the tagged module providers.
 *
 * @internal
 */
final class ModuleRegistryFactory
{
    /**
     * @param array<string, array{levels: list<string>, flags: list<string>, label: string}> $configured
     * @param iterable<ModuleDefinitionProviderInterface> $providers
     */
    public static function create(array $configured, iterable $providers = []): ModuleRegistry
    {
        $modules = [];
        foreach ($configured as $key => $module) {
            $modules[] = new ModuleDefinition($key, $module['levels'], $module['flags'], $module['label']);
        }
        foreach ($providers as $provider) {
            foreach ($provider->modules() as $module) {
                $modules[] = $module;
            }
        }

        return new ModuleRegistry($modules);
    }
}
