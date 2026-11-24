<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Domain;

/**
 * Hard limits of the token rules, read by the issuer and the configuration tree. `DependencyInjection\Configuration`
 * re-exports them with the same values (`Configuration::MAX_TTL_DAYS`, `Configuration::MAX_PER_OWNER_CAP`), so the
 * Application layer does not depend on the composition root (REQ-ARCH-002).
 */
final class ApiTokenLimits
{
    /** Tokens always expire (Q-O5): hosts may lower the maximum, never lift it. */
    public const MAX_TTL_DAYS = 365;

    /** Upper bound of `max_per_owner`. */
    public const MAX_PER_OWNER_CAP = 1000;
}
