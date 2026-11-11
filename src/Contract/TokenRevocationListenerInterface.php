<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Contract;

/**
 * Host / bridge SPI (autoconfigured, tag `nowo_api_token.revocation_listener`): runs after a revoked token's row is
 * deleted and committed, before the event. A failure is logged and does not undo the revocation.
 */
interface TokenRevocationListenerInterface
{
    /**
     * Called once per revoked token.
     */
    public function onRevoked(ApiTokenView $token, RevocationReason $reason): void;
}
