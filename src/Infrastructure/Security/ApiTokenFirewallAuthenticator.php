<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Infrastructure\Security;

use Nowo\ApiTokenBundle\Application\Token\TokenCodec;
use Nowo\ApiTokenBundle\Contract\ApiTokenAuthenticatorInterface;
use Nowo\ApiTokenBundle\Contract\ApiTokenPrincipal;
use Nowo\ApiTokenBundle\Contract\ApiTokenRateLimiterInterface;
use Nowo\ApiTokenBundle\Contract\AuthFailure;
use Nowo\ApiTokenBundle\Contract\Event\ApiTokenAuthFailedEvent;
use Nowo\ApiTokenBundle\Contract\LookupId;
use Nowo\ApiTokenBundle\Contract\RateLimitState;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

use function in_array;
use function is_string;
use function mb_substr;
use function preg_replace;

/**
 * The Bearer authenticator for Symfony firewalls (contract §6.3). Add it to a **stateless** firewall
 * (`custom_authenticators: [Nowo\ApiTokenBundle\Infrastructure\Security\ApiTokenFirewallAuthenticator]`); the container
 * refuses to build when a firewall that uses it is not stateless.
 *
 * - `supports()`: an `Authorization` header is present (scheme `Bearer`, case-insensitive, or bare). No query string,
 *   cookie or body credential is ever read.
 * - Before the presented value is parsed, the failed-token budget of the client IP is peeked: an IP over it is refused
 *   with no parsing and no database read.
 * - On success the per-token budget (one bucket for every module) and, with `rate_limit.count_rest_writes` on an unsafe
 *   method, the write budget are consumed. The principal and the rate limit answer are stamped on the request
 *   ({@see ApiTokenContext}) only once Symfony has accepted the passport (user checker included).
 * - Every token failure counts against the IP and dispatches {@see ApiTokenAuthFailedEvent}; every failure kind answers
 *   the client the same way, so it never learns whether a lookup id exists. `on_failure: pass` (default) lets the
 *   request through with no user, and each API answers its own refusal; `on_failure: unauthorized` answers
 *   `401 {"err": "Invalid token", "ECODE": "TOKEN_000"}` with `WWW-Authenticate: Bearer`.
 * - A rate limit refusal is not a token failure: with `on_failure: unauthorized` it answers
 *   `429 {"err": "Too many requests", "ECODE": "TOKEN_001"}` with `Retry-After`; with `pass` the request goes on with
 *   no user and the refused state on the request.
 *
 * Stateless (worker mode): nothing about a request, a token or an IP is kept on the service.
 */
final readonly class ApiTokenFirewallAuthenticator implements AuthenticatorInterface, AuthenticationEntryPointInterface
{
    /** `firewall.on_failure: pass` (default). */
    public const ON_FAILURE_PASS = 'pass';

    /** `firewall.on_failure: unauthorized`. */
    public const ON_FAILURE_UNAUTHORIZED = 'unauthorized';

    /** Attribute of the Symfony security token that carries the principal (also how the bundle recognises its tokens). */
    public const TOKEN_PRINCIPAL = 'nowo_api_token.principal';

    /** Attribute of the Symfony security token that carries the request's rate limit answer. */
    public const TOKEN_RATE_LIMIT = 'nowo_api_token.rate_limit';

    /** `ECODE` of an invalid, missing, expired or refused token. */
    public const ECODE_INVALID = 'TOKEN_000';

    /** `ECODE` of a rate limit refusal. */
    public const ECODE_RATE_LIMITED = 'TOKEN_001';

    /** Longest path stored in a failure event. */
    private const PATH_LENGTH = 200;

    /** The failures where the token exists, so the event may name its lookup id (contract §6.8). */
    private const KNOWN_TOKEN_FAILURES = [AuthFailure::WrongSecret, AuthFailure::Expired, AuthFailure::OwnerInactive];

    private ClockInterface $clock;

    /**
     * @param string $onFailure `firewall.on_failure` ({@see self::ON_FAILURE_PASS} or {@see self::ON_FAILURE_UNAUTHORIZED})
     * @param bool $countRestWrites `rate_limit.count_rest_writes`: unsafe methods also consume the write budget
     * @param ClockInterface|null $clock the rate limiter's time base for `Retry-After` (default: the system clock, which
     *                                   symfony/rate-limiter uses)
     */
    public function __construct(
        private ApiTokenAuthenticatorInterface $authenticator,
        private ApiTokenRateLimiterInterface $limiter,
        private TokenCodec $codec,
        private EventDispatcherInterface $dispatcher,
        private ClientIpHasher $ipHasher,
        private string $onFailure = self::ON_FAILURE_PASS,
        private bool $countRestWrites = false,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new NativeClock();
    }

    /**
     * True when the request carries an `Authorization` header. Removes any principal or rate limit attribute set
     * earlier in the request, so only this authenticator's own answer is ever read.
     */
    public function supports(Request $request): bool
    {
        ApiTokenContext::clear($request);

        return $request->headers->has('Authorization');
    }

    /**
     * Checks the IP budget, the token (contract §6.2) and the token's budgets.
     *
     * @throws ApiTokenRateLimitedException when a budget is used up
     * @throws ApiTokenAuthenticationException when the token is refused
     */
    public function authenticate(Request $request): SelfValidatingPassport
    {
        $ipState = $this->limiter->failedTokenState($this->clientIp($request));
        if (!$ipState->accepted) {
            throw new ApiTokenRateLimitedException($ipState);
        }

        $presented = $this->credential($request);
        $result    = $this->authenticator->authenticate($presented);
        if ($result instanceof AuthFailure) {
            throw new ApiTokenAuthenticationException($result, in_array($result, self::KNOWN_TOKEN_FAILURES, true) ? $this->codec->parse($presented) : null);
        }
        $user = $result->user;
        if (!$user instanceof UserInterface) {
            // A Symfony firewall can only hold a UserInterface: the token cannot sign this owner in.
            throw new ApiTokenAuthenticationException(AuthFailure::OwnerInactive, $this->codec->parse($presented));
        }

        $state = $this->limiter->consume($result->tokenId);
        if ($state->accepted && $this->countRestWrites && !$request->isMethodSafe()) {
            $state = $this->limiter->consumeWrite($result->tokenId);
        }
        if (!$state->accepted) {
            throw new ApiTokenRateLimitedException($state);
        }

        $passport = new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), static fn (): UserInterface => $user));
        $passport->setAttribute(self::TOKEN_PRINCIPAL, $result);
        $passport->setAttribute(self::TOKEN_RATE_LIMIT, $state);

        return $passport;
    }

    /**
     * A post-authentication token for the token's user, carrying the principal and the rate limit answer.
     */
    public function createToken(Passport $passport, string $firewallName): TokenInterface
    {
        $user  = $passport->getUser();
        $token = new PostAuthenticationToken($user, $firewallName, $user->getRoles());
        $token->setAttribute(self::TOKEN_PRINCIPAL, $passport->getAttribute(self::TOKEN_PRINCIPAL));
        $token->setAttribute(self::TOKEN_RATE_LIMIT, $passport->getAttribute(self::TOKEN_RATE_LIMIT));

        return $token;
    }

    /**
     * Stamps the principal and the rate limit answer on the request; the request goes on.
     */
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $principal = $token->hasAttribute(self::TOKEN_PRINCIPAL) ? $token->getAttribute(self::TOKEN_PRINCIPAL) : null;
        $state     = $token->hasAttribute(self::TOKEN_RATE_LIMIT) ? $token->getAttribute(self::TOKEN_RATE_LIMIT) : null;
        if ($principal instanceof ApiTokenPrincipal) {
            $request->attributes->set(ApiTokenContext::PRINCIPAL, $principal);
        }
        if ($state instanceof RateLimitState) {
            $request->attributes->set(ApiTokenContext::RATE_LIMIT, $state);
        }

        return null;
    }

    /**
     * Counts a token failure against the client IP and dispatches the failure event (rate limit refusals are neither);
     * answers per `on_failure`.
     */
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        ApiTokenContext::clear($request);
        if ($exception instanceof ApiTokenRateLimitedException) {
            $request->attributes->set(ApiTokenContext::RATE_LIMIT, $exception->state);

            return $this->onFailure === self::ON_FAILURE_UNAUTHORIZED ? $this->tooManyRequests($exception->state) : null;
        }

        if ($exception instanceof ApiTokenAuthenticationException) {
            $failure  = $exception->failure;
            $lookupId = $exception->lookupId;
        } else {
            // A valid token whose user Symfony refused afterwards (the firewall's user checker, a passport listener).
            $failure  = AuthFailure::OwnerInactive;
            $lookupId = $this->codec->parse($this->credential($request));
        }
        $clientIp = $this->clientIp($request);
        // @igor-ignore - counts in the host's cache pool (rate_limit.cache_pool); the limiter keeps no process state.
        $this->limiter->registerFailedToken($clientIp);
        $this->dispatcher->dispatch(new ApiTokenAuthFailedEvent($failure, $lookupId instanceof LookupId ? $lookupId : null, $this->ipHasher->short($clientIp), $this->path($request)));

        return $this->onFailure === self::ON_FAILURE_UNAUTHORIZED ? $this->invalid() : null;
    }

    /**
     * Entry point of a firewall whose access control needs a user: the same `401 TOKEN_000` answer.
     */
    public function start(Request $request, ?AuthenticationException $authException = null): JsonResponse
    {
        return $this->invalid();
    }

    /**
     * The presented value: the `Authorization` header without a leading `Bearer ` (case-insensitive).
     */
    private function credential(Request $request): string
    {
        return (string) preg_replace('/^Bearer\s+/i', '', (string) $request->headers->get('Authorization', ''));
    }

    private function clientIp(Request $request): string
    {
        return (string) $request->getClientIp();
    }

    /**
     * The route name, or the path without its query when the request has no route.
     */
    private function path(Request $request): string
    {
        $route = $request->attributes->get('_route');

        return mb_substr(is_string($route) && $route !== '' ? $route : $request->getPathInfo(), 0, self::PATH_LENGTH);
    }

    private function invalid(): JsonResponse
    {
        return new JsonResponse(['err' => ApiTokenAuthenticationException::MESSAGE, 'ECODE' => self::ECODE_INVALID], Response::HTTP_UNAUTHORIZED, [
            'WWW-Authenticate' => 'Bearer',
            'Cache-Control'    => 'no-store, private',
        ]);
    }

    private function tooManyRequests(RateLimitState $state): JsonResponse
    {
        return new JsonResponse(['err' => ApiTokenRateLimitedException::MESSAGE, 'ECODE' => self::ECODE_RATE_LIMITED], Response::HTTP_TOO_MANY_REQUESTS, [
            'Retry-After'   => (string) $state->retryAfterSeconds($this->clock->now()),
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
