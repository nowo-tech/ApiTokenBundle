<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Contract\Event;

use Nowo\ApiTokenBundle\Contract\OwnerRef;
use Nowo\ApiTokenBundle\Contract\TokenId;

/**
 * A token authenticated and its *last used* time was written (at most once a minute per token), so a listener can
 * never become a per-request write.
 */
final readonly class ApiTokenUsedEvent
{
    /**
     * @param string|null $module the module the request was for, when the caller knows it (bridges, S4)
     */
    public function __construct(public TokenId $tokenId, public OwnerRef $owner, public ?string $module = null)
    {
    }
}
