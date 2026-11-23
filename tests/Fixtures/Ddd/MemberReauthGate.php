<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Fixtures\Ddd;

use Nowo\ApiTokenBundle\Contract\ReauthGateInterface;
use SensitiveParameter;

/**
 * DDD host's own step-up (`reauth.gate`): a one-time code in its own form type, `000000` passes.
 */
final class MemberReauthGate implements ReauthGateInterface
{
    public function isFresh(object $user, #[SensitiveParameter] string $sessionKey): bool
    {
        return false;
    }

    public function form(): string
    {
        return MemberCodeType::class;
    }

    public function verify(object $user, #[SensitiveParameter] array $data): bool
    {
        return $user instanceof Member && ($data['code'] ?? null) === '000000';
    }
}
