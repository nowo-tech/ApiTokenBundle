<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Contract;

/**
 * Why a presented token was refused. Internal: it goes into events and logs; every case answers the client the same.
 */
enum AuthFailure: string
{
    case Missing       = 'missing';
    case Malformed     = 'malformed';
    case Unknown       = 'unknown';
    case WrongSecret   = 'wrong_secret';
    case Expired       = 'expired';
    case OwnerInactive = 'owner_inactive';
    case Disabled      = 'disabled';
}
