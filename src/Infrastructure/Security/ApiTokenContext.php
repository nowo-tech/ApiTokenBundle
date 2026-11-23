<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Infrastructure\Security;

use Nowo\ApiTokenBundle\Contract\ApiTokenPrincipal;
use Nowo\ApiTokenBundle\Contract\RateLimitState;
use Symfony\Component\HttpFoundation\Request;

/**
 * Reads what {@see ApiTokenFirewallAuthenticator} stamped on the request (contract §4.2). The principal lives only on
 * the request attributes, never on a service (worker mode), and only the authenticator writes it: it removes both
 * attributes before it authenticates, so a value set earlier in the request (a route default, another listener) is
 * never trusted.
 */
final class ApiTokenContext
{
    /** Request attribute of the {@see ApiTokenPrincipal}. */
    public const PRINCIPAL = '_nowo_api_token';

    /** Request attribute of the request's {@see RateLimitState} (accepted on success, refused on a 429). */
    public const RATE_LIMIT = '_nowo_api_token_rate_limit';

    /**
     * Request attribute set when the bundle's firewall authenticator signed the request in: the token storage is emptied
     * when the request finishes ({@see ApiTokenTokenStorageResetListener}). Never removed by {@see self::clear()}.
     */
    public const SIGNED_IN = '_nowo_api_token_signed_in';

    /**
     * The principal of a request the firewall authenticated with a token, else null.
     */
    public static function current(Request $request): ?ApiTokenPrincipal
    {
        $principal = $request->attributes->get(self::PRINCIPAL);

        return $principal instanceof ApiTokenPrincipal ? $principal : null;
    }

    /**
     * The rate limit answer of the request: the token's accepted per-token (or write) state on success, or the refused
     * state when a limit stopped it. Null when no limit was consulted (no token presented, or a token failure).
     */
    public static function rateLimit(Request $request): ?RateLimitState
    {
        $state = $request->attributes->get(self::RATE_LIMIT);

        return $state instanceof RateLimitState ? $state : null;
    }

    /**
     * Marks the request as signed in by the bundle's firewall authenticator.
     */
    public static function markSignedIn(Request $request): void
    {
        $request->attributes->set(self::SIGNED_IN, true);
    }

    /**
     * Whether the bundle's firewall authenticator signed the request in (any value counts: a forged mark only ever
     * empties the token storage, which fails closed).
     */
    public static function signedIn(Request $request): bool
    {
        return $request->attributes->has(self::SIGNED_IN);
    }

    /**
     * Removes both attributes (before authenticating, and on a failure).
     */
    public static function clear(Request $request): void
    {
        $request->attributes->remove(self::PRINCIPAL);
        $request->attributes->remove(self::RATE_LIMIT);
    }
}
