<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Contract;

/**
 * Which budget a {@see RateLimitState} describes (contract §6.7).
 */
enum RateLimitScope: string
{
    /** Requests of one token, shared by every module (`rate_limit.per_token`). */
    case Token = 'token';

    /** Writes of one token (`rate_limit.writes`). */
    case Write = 'write';

    /** Failed tokens of one client IP (`rate_limit.failed_per_ip`). */
    case FailedIp = 'failed_ip';
}
