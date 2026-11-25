<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Application\Clock;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * Fallback PSR-20 clock of the issuer and the authenticator when the host has no `clock` service: the system time in
 * PHP's default time zone, like Symfony's `NativeClock`, without depending on symfony/clock (REQ-ARCH-002: the
 * Application layer is framework-free).
 *
 * Stateless (worker mode).
 *
 * @internal
 */
final readonly class SystemClock implements ClockInterface
{
    /**
     * The current time.
     */
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now');
    }
}
