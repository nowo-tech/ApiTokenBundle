<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Domain;

/**
 * Port that mints the row ids of new tokens: a time-ordered UUIDv7 string (canonical lower-case form). The container
 * wires `Infrastructure\Identity\UuidV7IdGenerator` (symfony/uid, monotonic within a millisecond), so the Application
 * layer stays framework-free (REQ-ARCH-002).
 */
interface IdGeneratorInterface
{
    /**
     * A new UUIDv7 (36 characters, lower case).
     */
    public function next(): string;
}
