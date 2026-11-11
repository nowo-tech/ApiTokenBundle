<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Application\Default;

use Nowo\ApiTokenBundle\Contract\ApiTokenPolicyInterface;
use Nowo\ApiTokenBundle\Contract\ModuleRegistry;
use Nowo\ApiTokenBundle\Contract\OwnerRef;

/**
 * Default {@see ApiTokenPolicyInterface}: everybody may issue, every registered module, `expiry.max_days`.
 */
final readonly class ConfigApiTokenPolicy implements ApiTokenPolicyInterface
{
    /**
     * @param int $maxDays `expiry.max_days`
     */
    public function __construct(private ModuleRegistry $registry, private int $maxDays)
    {
    }

    /**
     * Always true.
     */
    public function mayIssue(OwnerRef $owner): bool
    {
        return true;
    }

    /**
     * Every registered module.
     */
    public function modulesFor(OwnerRef $owner): array
    {
        return $this->registry->keys();
    }

    /**
     * `expiry.max_days`.
     */
    public function maxTtlDays(OwnerRef $owner): int
    {
        return $this->maxDays;
    }
}
