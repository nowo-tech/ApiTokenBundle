<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Contract;

/**
 * Host SPI (rule 8): runtime switches. The default reads the configuration; set `settings_provider` to a service of
 * yours (e.g. backed by InstanceSettings). Read on every call, never cached by the bundle.
 */
interface ApiTokenSettingsProviderInterface
{
    /**
     * Off refuses every token ({@see AuthFailure::Disabled}) and every issue.
     */
    public function enabled(): bool;

    /**
     * Module key => on/off; a module that is off is dropped from every token's grants and cannot be issued. A module
     * missing from the map is on.
     *
     * @return array<string, bool>
     */
    public function modulesEnabled(): array;
}
