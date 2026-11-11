<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Contract;

/**
 * One keyset page of tokens (operator page, REQ-PERF-001): newest first; {@see self::$nextAfter} is the cursor of
 * the next page, null on the last one.
 */
final readonly class ApiTokenPage
{
    /**
     * @param list<ApiTokenView> $items
     */
    public function __construct(public array $items, public ?string $nextAfter)
    {
    }
}
