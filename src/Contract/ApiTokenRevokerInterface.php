<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Contract;

/**
 * Revokes tokens: deletes the row, runs the {@see TokenRevocationListenerInterface}s, then dispatches
 * {@see Event\ApiTokenRevokedEvent} after commit. The next request with that token is refused as unknown.
 */
interface ApiTokenRevokerInterface
{
    /**
     * Revokes one token of the owner; false when it does not exist or is not the owner's.
     */
    public function revoke(OwnerRef $owner, TokenId $id, RevocationReason $reason, ?OwnerRef $actor = null): bool;

    /**
     * Revokes every token of the owner; returns how many.
     */
    public function revokeAll(OwnerRef $owner, RevocationReason $reason, ?OwnerRef $actor = null): int;

    /**
     * Revokes any token (operator page), reason {@see RevocationReason::Operator}, the operator as actor.
     */
    public function revokeAsOperator(TokenId $id, OwnerRef $operator): bool;
}
