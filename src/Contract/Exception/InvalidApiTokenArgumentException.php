<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Contract\Exception;

use InvalidArgumentException;

/**
 * A value object or an issue request was given an invalid value. The message names the field, never a secret.
 */
final class InvalidApiTokenArgumentException extends InvalidArgumentException
{
}
