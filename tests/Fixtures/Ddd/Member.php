<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Fixtures\Ddd;

/**
 * DDD host: a plain domain class, mapped in XML on the host's own `ddd` entity manager.
 */
class Member
{
    public function __construct(private string $memberId, private bool $suspended = false)
    {
    }

    public function memberId(): string
    {
        return $this->memberId;
    }

    public function isSuspended(): bool
    {
        return $this->suspended;
    }

    public function suspend(): void
    {
        $this->suspended = true;
    }
}
