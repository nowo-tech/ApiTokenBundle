<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Domain;

use RuntimeException;

/**
 * The store refused an insert because the lookup id already exists; the issuer retries with a fresh one.
 */
final class LookupIdTakenException extends RuntimeException
{
}
