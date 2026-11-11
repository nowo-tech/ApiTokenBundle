<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Fixtures;

use Nowo\ApiTokenBundle\Contract\ApiTokenView;
use Nowo\ApiTokenBundle\Contract\RevocationReason;
use Nowo\ApiTokenBundle\Contract\TokenRevocationListenerInterface;
use RuntimeException;

/**
 * Test probe: records the bundle events and revocation callbacks it receives.
 */
final class RecordingListener implements TokenRevocationListenerInterface
{
    /** @var list<object> */
    public array $events = [];

    /** @var list<array{string, RevocationReason}> */
    public array $revoked = [];

    public bool $failRevocation = false;

    public function onEvent(object $event): void
    {
        $this->events[] = $event;
    }

    public function onRevoked(ApiTokenView $token, RevocationReason $reason): void
    {
        $this->revoked[] = [$token->id->value, $reason];
        if ($this->failRevocation) {
            throw new RuntimeException('listener failure');
        }
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    public function of(string $class): array
    {
        $found = [];
        foreach ($this->events as $event) {
            if ($event instanceof $class) {
                $found[] = $event;
            }
        }

        return $found;
    }

    public function clear(): void
    {
        $this->events  = [];
        $this->revoked = [];
    }
}
