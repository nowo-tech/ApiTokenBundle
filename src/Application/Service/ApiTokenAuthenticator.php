<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\ApiTokenBundle\Application\Token\TokenCodec;
use Nowo\ApiTokenBundle\Application\Token\TokenHasherInterface;
use Nowo\ApiTokenBundle\Contract\ApiTokenAuthenticatorInterface;
use Nowo\ApiTokenBundle\Contract\ApiTokenPrincipal;
use Nowo\ApiTokenBundle\Contract\ApiTokenSettingsProviderInterface;
use Nowo\ApiTokenBundle\Contract\AuthFailure;
use Nowo\ApiTokenBundle\Contract\Event\ApiTokenUsedEvent;
use Nowo\ApiTokenBundle\Contract\LookupId;
use Nowo\ApiTokenBundle\Contract\ModuleRegistry;
use Nowo\ApiTokenBundle\Contract\OwnerResolverInterface;
use Nowo\ApiTokenBundle\Contract\OwnerStatusInterface;
use Nowo\ApiTokenBundle\Domain\ApiToken;
use Nowo\ApiTokenBundle\Domain\ApiTokenStoreInterface;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SensitiveParameter;
use Symfony\Component\Clock\NativeClock;
use Throwable;

use function str_repeat;

/**
 * Default {@see ApiTokenAuthenticatorInterface} (contract §6.2). The order is fixed:
 *
 * 1. length and format (`Malformed`, no database read);
 * 2. checksum when present (`Malformed`, no database read);
 * 3. row by lookup id; when there is none, the hash is still computed and compared with a fixed dummy, so a miss and a
 *    mismatch cost the same (`Unknown`);
 * 4. `hash_equals()` of the stored hash (never `===`, never a SQL condition on the hash) (`WrongSecret`);
 * 5. `expires_at <= now` (`Expired`);
 * 6. module switch off (`Disabled`);
 * 7. owner erased or inactive, checked on every call with no cache (`OwnerInactive`);
 * 8. grants narrowed by the registry and the module switches (never widened);
 * 9. principal built; *last used* written when older than 60 s (outside any open transaction; a failure is logged at
 *    debug level and ignored), then {@see ApiTokenUsedEvent}.
 *
 * Stateless: nothing about a token, an owner or a request is kept between calls (worker mode).
 */
final readonly class ApiTokenAuthenticator implements ApiTokenAuthenticatorInterface
{
    /** *Last used* is written at most once per this many seconds per token. */
    public const LAST_USED_THROTTLE_SECONDS = 60;

    private ClockInterface $clock;

    /**
     * Wires the authenticator.
     */
    public function __construct(
        private ApiTokenStoreInterface $store,
        private TokenCodec $codec,
        private TokenHasherInterface $hasher,
        private ModuleRegistry $registry,
        private ApiTokenSettingsProviderInterface $settings,
        private OwnerResolverInterface $ownerResolver,
        private OwnerStatusInterface $ownerStatus,
        private EventDispatcherInterface $dispatcher,
        ?ClockInterface $clock = null,
        private ?LoggerInterface $logger = null,
    ) {
        $this->clock = $clock ?? new NativeClock();
    }

    /**
     * {@inheritDoc}
     */
    public function authenticate(#[SensitiveParameter] string $presented): ApiTokenPrincipal|AuthFailure
    {
        if ($presented === '') {
            return AuthFailure::Missing;
        }
        $lookupId = $this->codec->parse($presented);
        if (!$lookupId instanceof LookupId) {
            return AuthFailure::Malformed;
        }

        $token = $this->store->findByLookupId($lookupId);
        if (!$token instanceof ApiToken) {
            $this->hasher->matches($presented, str_repeat('0', 64), $this->hasher->currentVersion());

            return AuthFailure::Unknown;
        }
        if (!$this->hasher->matches($presented, $token->secretHash(), $token->hashVersion())) {
            return AuthFailure::WrongSecret;
        }

        // @igor-ignore - PSR clock read; DateTimeImmutable returns a new value, nothing is mutated.
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        if ($token->isExpiredAt($now)) {
            return AuthFailure::Expired;
        }
        if (!$this->settings->enabled()) {
            return AuthFailure::Disabled;
        }
        $owner = $token->owner();
        $user  = $this->ownerResolver->userOf($owner);
        if ($user === null || !$this->ownerStatus->isActive($user)) {
            return AuthFailure::OwnerInactive;
        }

        $allowed = [];
        $enabled = $this->settings->modulesEnabled();
        foreach ($this->registry->keys() as $module) {
            if ($enabled[$module] ?? true) {
                $allowed[] = $module;
            }
        }
        $principal = new ApiTokenPrincipal(
            $token->id(),
            $token->name(),
            $owner,
            $user,
            $this->registry->narrow($token->grants(), $allowed),
            $token->reach(),
            $token->expiresAt(),
        );

        $threshold = $now->modify('-' . self::LAST_USED_THROTTLE_SECONDS . ' seconds');
        $lastUsed  = $token->lastUsedAt();
        if (!$lastUsed instanceof DateTimeImmutable || $lastUsed < $threshold) {
            try {
                if ($this->store->touchLastUsed($token->id(), $now, $threshold)) {
                    $this->dispatcher->dispatch(new ApiTokenUsedEvent($token->id(), $owner));
                }
            } catch (Throwable $exception) {
                ($this->logger ?? new NullLogger())->debug('nowo_api_token: could not write last used of token {id}: {class}', ['id' => $token->id()->value, 'class' => $exception::class]);
            }
        }

        return $principal;
    }
}
