<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Infrastructure\Http;

use Nowo\ApiTokenBundle\Contract\Exception\ApiTokenConflictException;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Answers a lock conflict of the bundle's own services (REQ-RUNTIME-003 rule 6): when the failure that reaches the
 * kernel carries an {@see ApiTokenConflictException}, it is replaced by the policy's HTTP exception (409 by default,
 * or 503 with `conflict_response.status`), which the host's error page renders with:
 *
 * - `X-Conflict-Code: CONFLICT_001` and `Retry-After` when nothing was committed in the request;
 * - `X-Conflict-Code: CONFLICT_002` and no `Retry-After` when part of the call was committed, or anything else was
 *   committed earlier in the same request ({@see ConflictCode::forRequest()}, with transaction-doctrine's request
 *   watermark when the host enables `commit_detection`).
 *
 * Any other failure (a host's own deadlock, a unique violation, a lost connection, an exception already mapped to
 * HTTP) is left alone. transaction-doctrine's own responder, when the host registers it, runs first (priority 64) and
 * answers the same codes in its own shape. Stateless (worker mode).
 */
final readonly class ApiTokenConflictListener
{
    /** After transaction-doctrine's responder (64), before Symfony's ErrorListener (-128). */
    public const PRIORITY = 0;

    /**
     * Wires the listener.
     */
    public function __construct(private ConflictResponsePolicy $policy)
    {
    }

    /**
     * `kernel.exception` listener.
     */
    public function onKernelException(ExceptionEvent $event): void
    {
        $failure = $event->getThrowable();
        if ($failure instanceof HttpExceptionInterface) {
            return;
        }
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof ApiTokenConflictException) {
                $code = ConflictCode::forRequest($event->getRequest(), $failure);
                $event->setThrowable($this->policy->httpExceptionFor($code, ApiTokenConflictException::MESSAGE, $failure, $current->retryAfterSeconds()));

                return;
            }
        }
    }
}
