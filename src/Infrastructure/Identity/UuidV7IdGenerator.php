<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Infrastructure\Identity;

use Nowo\ApiTokenBundle\Domain\IdGeneratorInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Default {@see IdGeneratorInterface}: `Uuid::v7()` of symfony/uid (time-ordered, monotonic within a millisecond in
 * the same process).
 *
 * Stateless (worker mode): symfony/uid keeps its own monotonic counter, which is meant to live for the process.
 */
final readonly class UuidV7IdGenerator implements IdGeneratorInterface
{
    /**
     * A new UUIDv7 in its canonical lower-case form.
     */
    public function next(): string
    {
        return Uuid::v7()->toRfc4122();
    }
}
