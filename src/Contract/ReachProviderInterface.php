<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Contract;

/**
 * Host SPI: the places a person may choose for a token's reach today. The default offers none, so only *everything*
 * can be chosen (when `reach.allow_everything` is true).
 */
interface ReachProviderInterface
{
    /**
     * @return list<ReachChoice>
     */
    public function choicesFor(OwnerRef $owner): array;
}
