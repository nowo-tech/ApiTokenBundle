<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Contract\Event;

use Nowo\ApiTokenBundle\Contract\AuthFailure;
use Nowo\ApiTokenBundle\Contract\LookupId;

/**
 * A presented token was refused by the firewall (contract §6.3, §6.8). Dispatched on every failure, so listeners must
 * be cheap (the AuditKit bridge throttles it).
 *
 * Never carries the presented value, the hash, a raw IP or the query string.
 */
final readonly class ApiTokenAuthFailedEvent
{
    /**
     * @param LookupId|null $lookupId only for the failures where the token exists (`WrongSecret`, `Expired`,
     *                                `OwnerInactive`), else null
     * @param string $clientIpHash HMAC-SHA256 of the client IP with `kernel.secret`, 16 hex characters
     * @param string $path the route name, or the path without its query when the request has no route
     */
    public function __construct(
        public AuthFailure $failure,
        public ?LookupId $lookupId,
        public string $clientIpHash,
        public string $path,
    ) {
    }
}
