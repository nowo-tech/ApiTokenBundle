<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Infrastructure\Security;

use Nowo\ApiTokenBundle\Contract\ApiTokenRateLimiterInterface;
use Nowo\ApiTokenBundle\Contract\OwnerResolverInterface;
use Nowo\ApiTokenBundle\Contract\RateLimitState;
use Nowo\ApiTokenBundle\Contract\ReauthGateInterface;
use Psr\Clock\ClockInterface;
use SensitiveParameter;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\Request;

use function hash;
use function hash_equals;
use function is_array;
use function is_int;
use function is_string;

/**
 * The re-auth step of the account page (contract §6.10, `REQ-AUTH-013`). The freshness mark is a session value bound
 * to the session id and the owner: another browser, a new session (sign-in migrates the id) or another person in the
 * same session has to re-authenticate. Attempts are limited per session (`reauth.max_attempts` per 15 minutes).
 * Stateless: the mark lives only in the request's session.
 */
final readonly class ReauthSession
{
    /** Session key of the mark. */
    public const SESSION_KEY = '_nowo_api_token_reauth';

    private ClockInterface $clock;

    /**
     * @param bool $enabled `reauth.enabled`
     * @param int $freshSeconds `reauth.fresh_seconds`
     */
    public function __construct(
        private ReauthGateInterface $gate,
        private OwnerResolverInterface $owners,
        private ApiTokenRateLimiterInterface $limiter,
        private bool $enabled = true,
        private int $freshSeconds = 300,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new NativeClock();
    }

    /**
     * The gate's form type, or null for the password field.
     *
     * @return class-string|null
     */
    public function form(): ?string
    {
        return $this->gate->form();
    }

    /**
     * Whether this person re-authenticated in this session within `reauth.fresh_seconds` (always true when disabled).
     */
    public function isFresh(Request $request, object $user): bool
    {
        if (!$this->enabled) {
            return true;
        }
        $sessionId = $this->sessionId($request);
        if ($sessionId === '') {
            return false;
        }
        if ($this->gate->isFresh($user, $sessionId)) {
            return true;
        }
        $mark = $request->getSession()->get(self::SESSION_KEY);
        if (!is_array($mark) || !is_string($mark['sid'] ?? null) || !is_string($mark['owner'] ?? null) || !is_int($mark['at'] ?? null)) {
            return false;
        }
        $age = $this->clock->now()->getTimestamp() - $mark['at'];

        return hash_equals($mark['sid'], $this->digest($sessionId))
            && hash_equals($mark['owner'], $this->owners->ownerOf($user)->key())
            && $age >= 0 && $age <= $this->freshSeconds;
    }

    /**
     * One re-auth attempt: refused without counting the gate when the session used its attempts; on success the mark is
     * set. Returns the attempt budget's state and whether the data passed.
     *
     * @param array<string, mixed> $data
     *
     * @return array{RateLimitState, bool}
     */
    public function attempt(Request $request, object $user, #[SensitiveParameter] array $data): array
    {
        $sessionId = $this->sessionId($request);
        $state     = $this->limiter->consumeReauth($sessionId);
        if (!$state->accepted || $sessionId === '') {
            return [$state, false];
        }
        if (!$this->gate->verify($user, $data)) {
            return [$state, false];
        }
        $request->getSession()->set(self::SESSION_KEY, [
            'sid'   => $this->digest($sessionId),
            'owner' => $this->owners->ownerOf($user)->key(),
            'at'    => $this->clock->now()->getTimestamp(),
        ]);

        return [$state, true];
    }

    /**
     * Drops the mark (after *Revoke all*: the next destructive action asks again).
     */
    public function forget(Request $request): void
    {
        if ($request->hasSession()) {
            $request->getSession()->remove(self::SESSION_KEY);
        }
    }

    private function sessionId(Request $request): string
    {
        if (!$request->hasSession()) {
            return '';
        }
        $session = $request->getSession();
        if (!$session->isStarted()) {
            $session->start();
        }

        return $session->getId();
    }

    private function digest(#[SensitiveParameter] string $sessionId): string
    {
        return hash('sha256', $sessionId);
    }
}
