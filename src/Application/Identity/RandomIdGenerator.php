<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Application\Identity;

use Nowo\ApiTokenBundle\Domain\IdGeneratorInterface;

use function bin2hex;
use function chr;
use function microtime;
use function ord;
use function pack;
use function random_bytes;
use function sprintf;
use function substr;

/**
 * Fallback {@see IdGeneratorInterface} of an issuer built by hand without a generator: a plain-PHP UUIDv7 (48-bit Unix
 * milliseconds + 74 random bits). Two ids minted in the same millisecond are not ordered between them; the container
 * always wires the monotonic `Infrastructure\Identity\UuidV7IdGenerator` instead.
 *
 * Stateless (worker mode).
 *
 * @internal
 */
final readonly class RandomIdGenerator implements IdGeneratorInterface
{
    /**
     * A new UUIDv7 in its canonical lower-case form.
     */
    public function next(): string
    {
        $bytes    = substr(pack('J', (int) (microtime(true) * 1000)), 2) . random_bytes(10);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x70);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex      = bin2hex($bytes);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }
}
