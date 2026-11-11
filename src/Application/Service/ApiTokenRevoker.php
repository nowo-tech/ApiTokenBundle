<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Application\Service;

use Closure;
use DateTimeImmutable;
use Nowo\ApiTokenBundle\Contract\ApiTokenRevokerInterface;
use Nowo\ApiTokenBundle\Contract\Event\ApiTokenRevokedEvent;
use Nowo\ApiTokenBundle\Contract\OwnerRef;
use Nowo\ApiTokenBundle\Contract\RevocationReason;
use Nowo\ApiTokenBundle\Contract\TokenId;
use Nowo\ApiTokenBundle\Contract\TokenRevocationListenerInterface;
use Nowo\ApiTokenBundle\Domain\ApiToken;
use Nowo\ApiTokenBundle\Domain\ApiTokenStoreInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function count;

/**
 * Default {@see ApiTokenRevokerInterface} (contract §6.6): revocation deletes the row (no soft-revoked hash is kept);
 * after the commit, each {@see TokenRevocationListenerInterface} runs, then {@see ApiTokenRevokedEvent} is dispatched.
 * A failing listener is logged and never undoes the revocation or stops the other listeners.
 *
 * The owner is re-checked in PHP (`===`, audit LQ) before anything is deleted.
 */
final readonly class ApiTokenRevoker implements ApiTokenRevokerInterface
{
    /** Rows deleted per transaction by {@see self::revokeAll()} and {@see self::purgeExpired()}. */
    public const BATCH = 500;

    /**
     * @param iterable<TokenRevocationListenerInterface> $listeners
     */
    public function __construct(
        private ApiTokenStoreInterface $store,
        private iterable $listeners,
        private EventDispatcherInterface $dispatcher,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function revoke(OwnerRef $owner, TokenId $id, RevocationReason $reason, ?OwnerRef $actor = null): bool
    {
        $token = $this->store->findById($id);
        if (!$token instanceof ApiToken || !$token->owner()->equals($owner)) {
            return false;
        }

        return $this->deleteAll([$token], $reason, $actor) === 1;
    }

    /**
     * {@inheritDoc}
     */
    public function revokeAll(OwnerRef $owner, RevocationReason $reason, ?OwnerRef $actor = null): int
    {
        $total = 0;
        do {
            $batch   = $this->store->ofOwner($owner, self::BATCH);
            $deleted = $batch === [] ? 0 : $this->deleteAll($batch, $reason, $actor);
            $total += $deleted;
        } while ($deleted > 0 && count($batch) === self::BATCH);

        return $total;
    }

    /**
     * {@inheritDoc}
     */
    public function revokeAsOperator(TokenId $id, OwnerRef $operator): bool
    {
        $token = $this->store->findById($id);

        return $token instanceof ApiToken && $this->deleteAll([$token], RevocationReason::Operator, $operator) === 1;
    }

    /**
     * Deletes the rows that expired before `$cutoff`, in keyset batches by id (one transaction each), reason
     * {@see RevocationReason::Expired}; damaged rows are deleted too, without listeners or event (there is no token to
     * describe). Stops when `$continue` answers false between batches. Returns how many rows went.
     *
     * @param Closure(): bool|null $continue
     */
    public function purgeExpired(DateTimeImmutable $cutoff, int $batchSize = self::BATCH, ?Closure $continue = null): int
    {
        $total = 0;
        $after = null;
        do {
            $batch = $this->store->expiredBatch($cutoff, $batchSize, $after);
            if ($batch === []) {
                break;
            }
            $after = $batch[count($batch) - 1]['id'];
            /** @var array{count: int, tokens: list<ApiToken>} $deleted */
            $deleted = $this->store->transactional(
                // @igor-ignore - Closure passed to one transactional() call and released when it returns; never stored.
                function () use ($batch): array {
                    $count  = 0;
                    $tokens = [];
                    foreach ($batch as $row) {
                        if ($this->store->deleteRow($row['id'])) {
                            ++$count;
                            if ($row['token'] instanceof ApiToken) {
                                $tokens[] = $row['token'];
                            }
                        }
                    }

                    return ['count' => $count, 'tokens' => $tokens];
                },
                function (array $deleted): void {
                    foreach ($deleted['tokens'] as $token) {
                        $this->afterRevoke($token, RevocationReason::Expired, null);
                    }
                },
            );
            $total += $deleted['count'];
        } while (count($batch) === $batchSize && (!$continue instanceof Closure || $continue()));

        return $total;
    }

    /**
     * @param list<ApiToken> $tokens
     */
    private function deleteAll(array $tokens, RevocationReason $reason, ?OwnerRef $actor): int
    {
        /** @var list<ApiToken> $deleted */
        $deleted = $this->store->transactional(
            // @igor-ignore - Closure passed to one transactional() call and released when it returns; never stored.
            function () use ($tokens): array {
                $deleted = [];
                foreach ($tokens as $token) {
                    if ($this->store->delete($token->id())) {
                        $deleted[] = $token;
                    }
                }

                return $deleted;
            },
            // @igor-ignore - Closure passed to one transactional() call and released when it returns; never stored.
            function (array $deleted) use ($reason, $actor): void {
                foreach ($deleted as $token) {
                    $this->afterRevoke($token, $reason, $actor);
                }
            },
        );

        return count($deleted);
    }

    private function afterRevoke(ApiToken $token, RevocationReason $reason, ?OwnerRef $actor): void
    {
        $view = $token->toView();
        foreach ($this->listeners as $listener) {
            $this->guarded(static fn () => $listener->onRevoked($view, $reason));
        }
        $this->guarded(fn () => $this->dispatcher->dispatch(new ApiTokenRevokedEvent($view->id, $view->lookupId, $view->owner, $view->name, $reason, $actor)));
    }

    private function guarded(Closure $work): void
    {
        try {
            $work();
        } catch (Throwable $exception) {
            ($this->logger ?? new NullLogger())->error('nowo_api_token: a revocation listener failed: {class}', ['class' => $exception::class, 'exception' => $exception]);
        }
    }
}
