<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Application\Service;

use Nowo\ApiTokenBundle\Contract\ApiTokenPrincipal;
use Nowo\ApiTokenBundle\Contract\Reach;
use Nowo\ApiTokenBundle\Contract\ReachFilterInterface;

use function array_diff;
use function array_intersect;
use function array_values;

/**
 * Runs the {@see ReachFilterInterface} chain on a principal's stored reach (contract §6.2 step 8, §6.5) and keeps every
 * answer inside the reach it was given: a filter can drop entries or add exceptions to *everything*, never widen.
 */
final readonly class ReachFilterChain
{
    /**
     * @param iterable<ReachFilterInterface> $filters tagged `nowo_api_token.reach_filter`, in priority order
     */
    public function __construct(private iterable $filters)
    {
    }

    /**
     * The principal's reach after every filter.
     */
    public function apply(ApiTokenPrincipal $principal): Reach
    {
        $reach = $principal->reach;
        foreach ($this->filters as $filter) {
            $reach = self::clamp($reach, $filter->filter($principal, $reach));
        }

        return $reach;
    }

    /**
     * The part of `$after` that `$before` also reaches.
     */
    public static function clamp(Reach $before, Reach $after): Reach
    {
        if ($after->isEverything()) {
            // Everything minus X, inside $before: $before minus X.
            return $before->without($after->storedEntries());
        }
        if ($before->isEverything()) {
            return Reach::limitedTo(array_values(array_diff($after->allEntries(), $before->storedEntries())));
        }

        return Reach::limitedTo(array_values(array_intersect($after->allEntries(), $before->allEntries())));
    }
}
