<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Contract;

/**
 * Bridge / host SPI (autoconfigured, tag `nowo_api_token.module_provider`): contributes modules to the registry, next
 * to the `modules:` configuration. Read once when the registry service is built.
 */
interface ModuleDefinitionProviderInterface
{
    /**
     * @return iterable<ModuleDefinition>
     */
    public function modules(): iterable;
}
