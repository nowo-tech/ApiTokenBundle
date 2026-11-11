<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\ApiTokenBundle\Application\Token\TokenCodec;
use Nowo\ApiTokenBundle\Application\Token\TokenHasherInterface;
use Nowo\ApiTokenBundle\Contract\ApiTokenIssuerInterface;
use Nowo\ApiTokenBundle\Contract\ApiTokenPolicyInterface;
use Nowo\ApiTokenBundle\Contract\ApiTokenSettingsProviderInterface;
use Nowo\ApiTokenBundle\Contract\Event\ApiTokenIssuedEvent;
use Nowo\ApiTokenBundle\Contract\Exception\ApiTokenIssueRefusedException;
use Nowo\ApiTokenBundle\Contract\Exception\InvalidApiTokenArgumentException;
use Nowo\ApiTokenBundle\Contract\IssuedToken;
use Nowo\ApiTokenBundle\Contract\IssueRequest;
use Nowo\ApiTokenBundle\Contract\ModuleRegistry;
use Nowo\ApiTokenBundle\Contract\ReachProviderInterface;
use Nowo\ApiTokenBundle\DependencyInjection\Configuration;
use Nowo\ApiTokenBundle\Domain\ApiToken;
use Nowo\ApiTokenBundle\Domain\ApiTokenStoreInterface;
use Nowo\ApiTokenBundle\Domain\LookupIdTakenException;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Uid\Uuid;

use function count;
use function in_array;
use function mb_strtolower;
use function min;
use function preg_match;
use function sprintf;
use function trim;

/**
 * Default {@see ApiTokenIssuerInterface} (contract §6.1, §6.4–§6.6).
 *
 * Order: module switch, policy gate, name and channel, grants (registry, policy modules, module switches), reach
 * (allow-everything, the owner's choices, max entries), expiry (1 … min(`expiry.max_days`, policy, 365)), then, in one
 * transaction, the live-token cap and the per-owner name check, and the insert. The lookup id is regenerated on a
 * collision (3 tries). {@see ApiTokenIssuedEvent} is dispatched after commit.
 */
final readonly class ApiTokenIssuer implements ApiTokenIssuerInterface
{
    private const ATTEMPTS = 3;

    private ClockInterface $clock;

    /**
     * Wires the issuer.
     */
    public function __construct(
        private ApiTokenStoreInterface $store,
        private TokenCodec $codec,
        private TokenHasherInterface $hasher,
        private ModuleRegistry $registry,
        private ApiTokenPolicyInterface $policy,
        private ReachProviderInterface $reachProvider,
        private ApiTokenSettingsProviderInterface $settings,
        private EventDispatcherInterface $dispatcher,
        private int $maxPerOwner = 20,
        private int $maxDays = Configuration::MAX_TTL_DAYS,
        private bool $allowEverything = true,
        private int $maxReachEntries = 200,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new NativeClock();
    }

    /**
     * {@inheritDoc}
     */
    public function issue(IssueRequest $request): IssuedToken
    {
        $owner = $request->owner;
        if (!$this->settings->enabled()) {
            throw new ApiTokenIssueRefusedException(ApiTokenIssueRefusedException::DISABLED, 'API tokens are switched off.');
        }
        if (!$this->policy->mayIssue($owner)) {
            throw new ApiTokenIssueRefusedException(ApiTokenIssueRefusedException::POLICY, 'This owner may not issue API tokens.');
        }
        $name = trim($request->name);
        if (preg_match('/^[^\p{Cc}]{1,' . ApiToken::MAX_NAME_LENGTH . '}$/Du', $name) !== 1) {
            throw new InvalidApiTokenArgumentException(sprintf('Invalid token name: expected 1 to %d characters without control characters.', ApiToken::MAX_NAME_LENGTH));
        }
        if (preg_match('/^[a-z][a-z0-9_]{0,15}$/D', $request->createdVia) !== 1) {
            throw new InvalidApiTokenArgumentException('Invalid channel: expected ^[a-z][a-z0-9_]{0,15}$.');
        }

        try {
            $this->registry->assertValid($request->grants, $this->allowedModules($request));
        } catch (InvalidApiTokenArgumentException $exception) {
            throw new ApiTokenIssueRefusedException(ApiTokenIssueRefusedException::GRANTS, $exception->getMessage());
        }
        $this->assertReach($request);

        $maxDays = min($this->maxDays, $this->policy->maxTtlDays($owner), Configuration::MAX_TTL_DAYS);
        if ($request->expiresInDays < 1 || $request->expiresInDays > $maxDays) {
            throw new ApiTokenIssueRefusedException(ApiTokenIssueRefusedException::EXPIRY, sprintf('The expiry must be between 1 and %d days.', $maxDays));
        }

        // @igor-ignore - PSR clock read; DateTimeImmutable returns a new value, nothing is mutated.
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        // @igor-ignore - DateTimeImmutable::setTime() returns a new value.
        $now       = $now->setTime((int) $now->format('H'), (int) $now->format('i'), (int) $now->format('s'));
        $expiresAt = $now->modify(sprintf('+%d days', $request->expiresInDays));

        for ($attempt = 1;; ++$attempt) {
            $generated = $this->codec->generate();
            $token     = new ApiToken(
                Uuid::v7()->toRfc4122(),
                $generated->lookupId->value,
                $this->hasher->hash($generated->token),
                $this->hasher->currentVersion(),
                $owner->type,
                $owner->id,
                $name,
                $request->grants->toArray(),
                $request->reach->isEverything(),
                $request->reach->storedEntries(),
                $now,
                $expiresAt,
                null,
                $request->createdVia,
            );
            try {
                $this->store->transactional(
                    // @igor-ignore - Closure passed to one transactional() call and released when it returns; never stored.
                    function () use ($token, $owner, $name, $now): void {
                        if ($this->store->countLive($owner, $now) >= $this->maxPerOwner) {
                            throw new ApiTokenIssueRefusedException(ApiTokenIssueRefusedException::TOO_MANY, sprintf('An owner holds at most %d live tokens.', $this->maxPerOwner));
                        }
                        foreach ($this->store->ofOwner($owner, Configuration::MAX_PER_OWNER_CAP) as $existing) {
                            if (mb_strtolower($existing->name()) === mb_strtolower($name)) {
                                throw new ApiTokenIssueRefusedException(ApiTokenIssueRefusedException::DUPLICATE_NAME, 'Another token of this owner has the same name.');
                            }
                        }
                        $this->store->insert($token);
                    },
                    // @igor-ignore - Closure passed to one transactional() call and released when it returns; never stored.
                    function () use ($token, $request): void {
                        $this->dispatcher->dispatch(new ApiTokenIssuedEvent(
                            $token->id(),
                            $token->lookupId(),
                            $token->owner(),
                            $token->name(),
                            $token->grants(),
                            $request->reach->summary(),
                            $token->expiresAt(),
                            $token->createdVia(),
                        ));
                    },
                );
            } catch (LookupIdTakenException $exception) {
                if ($attempt >= self::ATTEMPTS) {
                    throw $exception;
                }

                continue;
            }

            return new IssuedToken($token->toView(), $generated->token);
        }
    }

    /**
     * @return list<string>
     */
    private function allowedModules(IssueRequest $request): array
    {
        $policyModules = $this->policy->modulesFor($request->owner);
        $switches      = $this->settings->modulesEnabled();
        $allowed       = [];
        foreach ($this->registry->keys() as $module) {
            if (in_array($module, $policyModules, true) && ($switches[$module] ?? true)) {
                $allowed[] = $module;
            }
        }

        return $allowed;
    }

    private function assertReach(IssueRequest $request): void
    {
        $reach = $request->reach;
        if ($reach->isEverything()) {
            if (!$this->allowEverything) {
                throw new ApiTokenIssueRefusedException(ApiTokenIssueRefusedException::REACH, 'Choose the places this token may reach.');
            }
            if (count($reach->exceptions()) > $this->maxReachEntries) {
                throw new ApiTokenIssueRefusedException(ApiTokenIssueRefusedException::REACH, sprintf('A reach holds at most %d entries.', $this->maxReachEntries));
            }

            return;
        }
        $entries = $reach->allEntries();
        if ($entries === []) {
            throw new ApiTokenIssueRefusedException(ApiTokenIssueRefusedException::REACH, 'A token must reach at least one place.');
        }
        if (count($entries) > $this->maxReachEntries) {
            throw new ApiTokenIssueRefusedException(ApiTokenIssueRefusedException::REACH, sprintf('A reach holds at most %d entries.', $this->maxReachEntries));
        }
        $choices = [];
        foreach ($this->reachProvider->choicesFor($request->owner) as $choice) {
            $choices[$choice->key] = true;
        }
        foreach ($entries as $entry) {
            if (!isset($choices[$entry])) {
                throw new ApiTokenIssueRefusedException(ApiTokenIssueRefusedException::REACH, 'The reach names a place this owner cannot choose.');
            }
        }
    }
}
