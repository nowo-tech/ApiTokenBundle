<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Contract;

use Nowo\ApiTokenBundle\Contract\Exception\InvalidApiTokenArgumentException;

use function preg_match;

/**
 * One place a person may choose for a token's reach ({@see ReachProviderInterface}): the stored entry key, a label
 * and a group heading for the form.
 */
final readonly class ReachChoice
{
    /**
     * @throws InvalidApiTokenArgumentException when the key is not a valid reach entry
     */
    public function __construct(public string $key, public string $label, public string $group = '')
    {
        if (preg_match(Reach::ENTRY_PATTERN, $key) !== 1) {
            throw new InvalidApiTokenArgumentException('Invalid reach choice key: expected ^[A-Za-z0-9._:-]{1,128}$.');
        }
    }
}
