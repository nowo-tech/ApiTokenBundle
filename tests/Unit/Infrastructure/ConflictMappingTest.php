<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Unit\Infrastructure;

use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nowo\ApiTokenBundle\Contract\Exception\ApiTokenConflictException;
use Nowo\ApiTokenBundle\Domain\ApiTokenOwnerLock;
use Nowo\ApiTokenBundle\Infrastructure\Doctrine\Entity\ApiTokenOwnerLockRecord;
use Nowo\ApiTokenBundle\Infrastructure\Http\ApiTokenConflictListener;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\CommitMark;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

/**
 * REQ-RUNTIME-003 rule 6: the conflict exception's codes and the HTTP answer (409 / 503, Retry-After, X-Conflict-Code)
 * of the bundle's conflict listener, including the request-level watermark.
 */
final class ConflictMappingTest extends TestCase
{
    public function testARetrySafeConflictCarriesCode001AndTheHint(): void
    {
        $deadlock = $this->deadlock();
        $conflict = ApiTokenConflictException::retrySafe($deadlock, TransientFailureKind::Deadlock, 3, 2);

        self::assertSame(ConflictCode::RetrySafe, $conflict->conflictCode);
        self::assertSame(ConflictCode::RetrySafe, ConflictCode::forFailure($conflict));
        self::assertSame(2, $conflict->retryAfterSeconds());
        self::assertSame(3, $conflict->conflict()->attempts);
        self::assertSame(TransientFailureKind::Deadlock, $conflict->conflict()->kind);
        self::assertFalse($conflict->conflict()->committed);
        self::assertSame($deadlock, $conflict->conflict()->getPrevious());
        self::assertSame(ApiTokenConflictException::MESSAGE, $conflict->getMessage());
    }

    public function testAfterAPartialCommitTheCodeIs002WithoutHint(): void
    {
        $deadlock = $this->deadlock();
        $partial  = ApiTokenConflictException::retrySafe($deadlock, TransientFailureKind::LockWaitTimeout, 2, 1)->afterPartialCommit();

        self::assertSame(ConflictCode::Committed, $partial->conflictCode);
        self::assertSame(ConflictCode::Committed, ConflictCode::forFailure($partial));
        self::assertNull($partial->retryAfterSeconds());
        self::assertTrue($partial->conflict()->committed);
        self::assertSame(2, $partial->conflict()->attempts);
        self::assertSame(TransientFailureKind::LockWaitTimeout, $partial->conflict()->kind);
        self::assertSame($deadlock, $partial->conflict()->getPrevious());
        self::assertSame($partial, $partial->afterPartialCommit());
    }

    public function testTheListenerAnswers409With001(): void
    {
        $answer = $this->answer(ApiTokenConflictException::retrySafe($this->deadlock(), TransientFailureKind::Deadlock, 1, 1));

        self::assertInstanceOf(HttpExceptionInterface::class, $answer);
        self::assertSame(409, $answer->getStatusCode());
        self::assertSame(['X-Conflict-Code' => 'CONFLICT_001', 'Retry-After' => '1'], $answer->getHeaders());
        self::assertSame(ApiTokenConflictException::MESSAGE, $answer->getMessage());
    }

    public function testTheListenerFindsAWrappedConflictAndHonours503(): void
    {
        $wrapped = new RuntimeException('host wrapper', 0, ApiTokenConflictException::committed($this->deadlock(), TransientFailureKind::Retryable));
        $answer  = $this->answer($wrapped, new ConflictResponsePolicy(503, 5));

        self::assertInstanceOf(HttpExceptionInterface::class, $answer);
        self::assertSame(503, $answer->getStatusCode());
        self::assertSame(['X-Conflict-Code' => 'CONFLICT_002'], $answer->getHeaders());
    }

    public function testAnEarlierCommitInTheRequestTurns001Into002(): void
    {
        $ledger  = new CommitLedger();
        $request = Request::create('/api/tokens', 'POST');
        $request->attributes->set(CommitMark::REQUEST_ATTRIBUTE, new CommitMark($ledger));
        $ledger->record();

        $answer = $this->answer(ApiTokenConflictException::retrySafe($this->deadlock(), TransientFailureKind::Deadlock, 1, 1), null, $request);

        self::assertInstanceOf(HttpExceptionInterface::class, $answer);
        self::assertSame(['X-Conflict-Code' => 'CONFLICT_002'], $answer->getHeaders());
    }

    public function testOtherFailuresAreLeftAlone(): void
    {
        $unique = new UniqueConstraintViolationException(new class('duplicate') extends AbstractException {
        }, null);
        self::assertSame($unique, $this->answer($unique), 'a unique violation is not a conflict');
        $deadlock = $this->deadlock();
        self::assertSame($deadlock, $this->answer($deadlock), 'a host deadlock is the host\'s to answer');
        $http = new HttpException(409, 'mapped', ApiTokenConflictException::retrySafe($this->deadlock(), TransientFailureKind::Deadlock, 1, 1));
        self::assertSame($http, $this->answer($http), 'already mapped');
    }

    public function testTheLockRowClassesHoldTheColumns(): void
    {
        self::assertSame(['user', '1', 1], [(new ApiTokenOwnerLock('user', '1'))->ownerType, (new ApiTokenOwnerLock('user', '1'))->ownerId, (new ApiTokenOwnerLock('user', '1'))->version]);
        $record = new ApiTokenOwnerLockRecord('member', 'm-1', 4);
        self::assertSame(['member', 'm-1', 4], [$record->ownerType, $record->ownerId, $record->version]);
    }

    private function answer(Throwable $failure, ?ConflictResponsePolicy $policy = null, ?Request $request = null): Throwable
    {
        $event = new ExceptionEvent($this->createStub(HttpKernelInterface::class), $request ?? Request::create('/api/tokens', 'POST'), HttpKernelInterface::MAIN_REQUEST, $failure);
        (new ApiTokenConflictListener($policy ?? new ConflictResponsePolicy()))->onKernelException($event);

        return $event->getThrowable();
    }

    private function deadlock(): DeadlockException
    {
        return new DeadlockException(new class('Deadlock found', '40001', 1213) extends AbstractException {
        }, null);
    }
}
