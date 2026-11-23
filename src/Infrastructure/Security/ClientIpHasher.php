<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Infrastructure\Security;

use SensitiveParameter;

use function hash_hmac;
use function substr;

/**
 * Keys a client IP by HMAC-SHA256 with `kernel.secret`, so neither the limiter's cache keys nor the failure events hold
 * a raw IP (contract §6.8, REQ-DATA-001).
 */
final readonly class ClientIpHasher
{
    /** Length of {@see self::short()} (contract §6.8). */
    public const SHORT_LENGTH = 16;

    /**
     * @param string $secret `kernel.secret`
     */
    public function __construct(#[SensitiveParameter] private string $secret)
    {
    }

    /**
     * The full HMAC (64 hex characters), for limiter keys.
     */
    public function full(string $clientIp): string
    {
        return hash_hmac('sha256', $clientIp, $this->secret);
    }

    /**
     * The first 16 hex characters of the HMAC, for events.
     */
    public function short(string $clientIp): string
    {
        return substr($this->full($clientIp), 0, self::SHORT_LENGTH);
    }
}
