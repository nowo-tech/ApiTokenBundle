<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Application\Default;

use Nowo\ApiTokenBundle\Contract\ApiTokenSettingsProviderInterface;

/**
 * Default {@see ApiTokenSettingsProviderInterface}: the configuration (`enabled`; every module on). Registered only
 * while `enabled` is true, so it answers true.
 */
final readonly class ConfigSettingsProvider implements ApiTokenSettingsProviderInterface
{
    /**
     * @param array<string, bool> $modules module switches; missing = on
     */
    public function __construct(private bool $enabled = true, private array $modules = [])
    {
    }

    /**
     * The configured switch.
     */
    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * The configured module switches.
     */
    public function modulesEnabled(): array
    {
        return $this->modules;
    }
}
