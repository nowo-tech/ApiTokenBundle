<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Contract;

/**
 * Why a token was revoked (its row deleted); carried by {@see Event\ApiTokenRevokedEvent}.
 */
enum RevocationReason: string
{
    case Owner       = 'owner';
    case OwnerAll    = 'owner_all';
    case Operator    = 'operator';
    case SelfApi     = 'self_api';
    case Erasure     = 'erasure';
    case Compromised = 'compromised';
    case Expired     = 'expired_purge';
}
