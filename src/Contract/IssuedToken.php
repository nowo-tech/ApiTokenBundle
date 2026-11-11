<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Contract;

use JsonSerializable;
use LogicException;
use SensitiveParameter;

/**
 * A freshly issued token: its view and the full token string, returned once. The secret is never stored, logged or
 * put into an event; a lost secret means a new token.
 *
 * The secret is read with {@see self::secret()} only (audit LW-L1). It is kept in a {@see SensitiveValue}, so `var_export()`,
 * an `(array)` cast, `print_r()` / `var_dump()` and Symfony VarDumper (`dump()`, the profiler) never show it;
 * `json_encode()` gives the view and `"[redacted]"`; `serialize()` / `unserialize()` throw, so it never reaches a
 * session, a cache, a Messenger envelope or a log context in clear.
 */
final readonly class IssuedToken implements JsonSerializable
{
    /** Shown instead of the secret. */
    public const REDACTED = '[redacted]';

    private SensitiveValue $secret;

    /**
     * @param string $secret the full token string (`<prefix><lookup id>_<secret><checksum>`)
     */
    public function __construct(public ApiTokenView $token, #[SensitiveParameter] string $secret)
    {
        $this->secret = new SensitiveValue($secret);
    }

    /**
     * The full token string, to show once to the person who created it.
     */
    public function secret(): string
    {
        return $this->secret->reveal();
    }

    /**
     * The view and a redacted secret.
     *
     * @return array{token: ApiTokenView, secret: string}
     */
    public function jsonSerialize(): array
    {
        return ['token' => $this->token, 'secret' => self::REDACTED];
    }

    /**
     * Never prints the secret (`print_r()`, `var_dump()`, VarDumper).
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['token' => $this->token, "\0" . self::class . "\0secret" => self::REDACTED];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('An issued API token cannot be serialized.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('An issued API token cannot be unserialized.');
    }
}
