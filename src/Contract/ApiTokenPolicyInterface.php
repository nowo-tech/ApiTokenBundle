<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Contract;

/**
 * Host SPI: the plan gate. The default reads the configuration. Override the service
 * `Nowo\ApiTokenBundle\Contract\ApiTokenPolicyInterface` to plug a plan system.
 */
interface ApiTokenPolicyInterface
{
    /**
     * Whether the owner may issue tokens at all.
     */
    public function mayIssue(OwnerRef $owner): bool;

    /**
     * The module keys the owner may grant.
     *
     * @return list<string>
     */
    public function modulesFor(OwnerRef $owner): array;

    /**
     * The owner's longest lifetime in days; the bundle clamps it to `expiry.max_days` (≤ 365): hosts may lower the
     * maximum, never lift it.
     */
    public function maxTtlDays(OwnerRef $owner): int;
}
