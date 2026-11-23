<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Contract;

use SensitiveParameter;

/**
 * The rate limits of contract §6.7, kept in the host's cache pool (`rate_limit.cache_pool`), never in process memory.
 *
 * - One bucket per token for every module ({@see self::consume()}): the firewall consumes it once per authenticated
 *   request, so a client cannot double its budget by switching API or protocol. Bridges and host APIs read that answer
 *   from the request (`ApiTokenContext::rateLimit()`) instead of consuming again.
 * - A write sub-budget per token ({@see self::consumeWrite()}).
 * - Tokens issued per owner from the account page, and re-authentication attempts per session (S3).
 * - Failed tokens per client IP: peeked before the presented value is parsed ({@see self::failedTokenState()}), counted
 *   on every failure ({@see self::registerFailedToken()}). The IP is keyed by an HMAC, never stored in clear.
 */
interface ApiTokenRateLimiterInterface
{
    /**
     * Counts one request of the token.
     */
    public function consume(TokenId $tokenId): RateLimitState;

    /**
     * Counts one write of the token.
     */
    public function consumeWrite(TokenId $tokenId): RateLimitState;

    /**
     * Whether the client IP may still present a token; counts nothing while the IP has budget left.
     *
     * @param string $clientIp the client IP ('' when unknown: every such request shares one bucket)
     */
    public function failedTokenState(string $clientIp): RateLimitState;

    /**
     * Counts one failed token of the client IP.
     *
     * @param string $clientIp the client IP ('' when unknown)
     */
    public function registerFailedToken(string $clientIp): void;

    /**
     * Counts one token issued from the account page for the owner (`rate_limit.issue_per_owner`).
     */
    public function consumeIssue(OwnerRef $owner): RateLimitState;

    /**
     * Counts one re-authentication attempt of a session (`reauth.max_attempts` per 15 minutes).
     *
     * @param string $sessionKey the session id (keyed by HMAC, never stored in clear)
     */
    public function consumeReauth(#[SensitiveParameter] string $sessionKey): RateLimitState;
}
