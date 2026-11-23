<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Presentation\View;

use Nowo\ApiTokenBundle\Contract\ApiTokenView;

/**
 * One row of the account or operator table: the token's view plus what the template prints (never the secret or the
 * hash).
 */
final readonly class TokenRow
{
    /**
     * @param string $handle the public handle (`<prefix><first 4 of the lookup id>…`)
     * @param list<array{module: string, level: string, flags: list<string>}> $modules granted modules, in registry order
     * @param bool $expiresSoon expires within {@see AccountPages::EXPIRY_WARNING_DAYS} days
     * @param bool $destructive a module flag `destructive` is ticked (*agents may delete*)
     */
    public function __construct(
        public ApiTokenView $token,
        public string $handle,
        public array $modules,
        public bool $expiresSoon,
        public bool $destructive,
    ) {
    }
}
