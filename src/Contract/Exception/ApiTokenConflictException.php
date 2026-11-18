<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Contract\Exception;

use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use Nowo\TransactionDoctrine\WriteUnit\LockConflictExceptionInterface;
use Throwable;

use function assert;

/**
 * A token write met a database lock conflict (deadlock, lock wait timeout, serialization failure) on every attempt, or
 * lost its savepoint with an unknown outcome (REQ-RUNTIME-003 rule 6).
 *
 * - {@see self::$conflictCode} is the unit's own code: `CONFLICT_001` (nothing of this call was committed, retry is safe) or
 *   `CONFLICT_002` (an earlier batch of the same call was committed, or the outcome is unknown: read again, do not
 *   repeat). An HTTP answer uses {@see ConflictCode::forRequest()}, which also answers `CONFLICT_002` when anything
 *   else was committed earlier in the same request.
 * - The previous exception is a transaction-doctrine {@see ConcurrencyConflict} (with `committed` set), whose previous
 *   is the storage error: {@see ConflictCode::forFailure()} reads the code from the chain, and transaction-doctrine's
 *   conflict responder recognises the failure through {@see LockConflictExceptionInterface}.
 * - The message is constant and client-safe (no SQL, no parameters). Unique violations and lost connections are never
 *   wrapped in this exception.
 */
final class ApiTokenConflictException extends TransientStorageFailure implements LockConflictExceptionInterface
{
    public const MESSAGE = 'The API token change met a concurrent change in the database. Retry later.';

    /**
     * @param ConflictCode $conflictCode The unit's conflict code
     * @param ConcurrencyConflict $conflict The transaction-doctrine conflict (attempts, kind, committed, storage error)
     */
    private function __construct(public readonly ConflictCode $conflictCode, ConcurrencyConflict $conflict)
    {
        parent::__construct($conflictCode->retrySafe() ? $conflict->retryAfterSeconds() : null, self::MESSAGE, $conflict);
    }

    /**
     * A lock conflict of a unit that committed nothing (`CONFLICT_001`).
     *
     * @param Throwable $failure The storage error (or the transient failure that wraps it)
     * @param int $attempts Attempts made (1 or more)
     * @param int|null $retryAfterSeconds Seconds after which a retry is sensible; null = no hint
     */
    public static function retrySafe(Throwable $failure, TransientFailureKind $kind, int $attempts, ?int $retryAfterSeconds): self
    {
        return new self(ConflictCode::RetrySafe, new ConcurrencyConflict($attempts, $kind, $retryAfterSeconds, $failure));
    }

    /**
     * A failure after which part of the call may be committed (`CONFLICT_002`, no Retry-After): a lost savepoint with
     * no deadlock behind it, or a conflict in a later batch of a batched call.
     *
     * @param Throwable $failure The storage error (or the conflict of the failed batch)
     * @param int $attempts Attempts made (1 or more)
     */
    public static function committed(Throwable $failure, TransientFailureKind $kind, int $attempts = 1): self
    {
        return new self(ConflictCode::Committed, new ConcurrencyConflict($attempts, $kind, null, $failure, true));
    }

    /**
     * This conflict, met after earlier batches of the same call were committed: `CONFLICT_002`.
     */
    public function afterPartialCommit(): self
    {
        $conflict = $this->conflict();

        return $this->conflictCode === ConflictCode::Committed ? $this : self::committed($conflict->getPrevious() ?? $conflict, $conflict->kind, $conflict->attempts);
    }

    /**
     * The transaction-doctrine conflict this exception wraps.
     */
    public function conflict(): ConcurrencyConflict
    {
        $previous = $this->getPrevious();
        assert($previous instanceof ConcurrencyConflict);

        return $previous;
    }
}
