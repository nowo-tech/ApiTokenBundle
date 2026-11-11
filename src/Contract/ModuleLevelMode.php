<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Contract;

/**
 * How a module's grant is chosen: a ladder of ordered levels (one select) or a single on/off level (one tick).
 * Flags are always independent ticks on top of either mode.
 */
enum ModuleLevelMode
{
    case Ordered;
    case Flags;
}
