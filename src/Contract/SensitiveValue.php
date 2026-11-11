<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Contract;

use JsonSerializable;
use LogicException;
use SensitiveParameter;
use WeakMap;

/**
 * Holds a secret string so that no generic channel prints it (audit LW-L1). The object has no property: the value
 * lives in a class-private weak map keyed by the object, so an `(array)` cast, `var_export()`, `print_r()`,
 * `var_dump()` and Symfony VarDumper see nothing, `json_encode()` gives `"[redacted]"`, and `serialize()` /
 * `unserialize()` throw. {@see self::reveal()} is the only way to read it.
 *
 * Worker mode: the weak map holds an entry only while its object lives (the request that issued the token), so
 * nothing survives the request.
 *
 * @internal used by {@see IssuedToken} and the token generator
 */
final class SensitiveValue implements JsonSerializable
{
    /** Shown instead of the value. */
    public const REDACTED = '[redacted]';

    /** @var WeakMap<self, string>|null */
    // @phpstan-ignore frankenphp.worker.noMutableStaticProperty (weak map keyed by the holder: an entry dies with its object, nothing outlives the request)
    private static ?WeakMap $values = null;

    /**
     * Wraps the value.
     */
    public function __construct(#[SensitiveParameter] string $value)
    {
        // @igor-ignore - Weak map keyed by this object: the entry dies with it, nothing outlives the request.
        // @phpstan-ignore frankenphp.worker.noMutableStaticProperty (weak map keyed by the holder: an entry dies with its object, nothing outlives the request)
        self::$values ??= new WeakMap();
        // @phpstan-ignore frankenphp.worker.noMutableStaticProperty (weak map keyed by the holder: an entry dies with its object, nothing outlives the request)
        self::$values[$this] = $value;
    }

    /**
     * The secret.
     */
    public function reveal(): string
    {
        return self::$values[$this] ?? '';
    }

    /**
     * Always `"[redacted]"`.
     */
    public function jsonSerialize(): string
    {
        return self::REDACTED;
    }

    /**
     * Never prints the value.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['value' => self::REDACTED];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('A secret cannot be serialized.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('A secret cannot be unserialized.');
    }
}
