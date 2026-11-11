<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Application\Token;

use Nowo\ApiTokenBundle\Contract\LookupId;
use SensitiveParameter;

use function base64_encode;
use function crc32;
use function hash_equals;
use function intdiv;
use function ord;
use function preg_match;
use function preg_quote;
use function random_bytes;
use function rtrim;
use function str_pad;
use function strlen;
use function strtr;
use function substr;

use const STR_PAD_LEFT;

/**
 * Token format (contract §6.1, Q-O3): `<prefix><lookup id>_<secret>[<checksum>]`.
 *
 * - prefix: `token_prefix` (`^[a-z]{2,8}_$`, default `nwt_`, Beacon `bt_`);
 * - lookup id: 12 characters of `[a-z2-7]` (60 random bits), stored in clear;
 * - secret: 32 random bytes (256 bits), base64url without padding (43 characters);
 * - checksum: CRC32 of everything before it, base62, 6 characters (offline typo check, secret scanning).
 *
 * Parsing accepts the token with or without a checksum: the stored hash covers the whole string, so a stripped or
 * altered checksum never authenticates. A present but wrong checksum is refused before any database read.
 *
 * @internal
 */
final readonly class TokenCodec
{
    /** Longer presented values are refused before any parsing. */
    public const MAX_LENGTH = 128;

    private const BASE32 = 'abcdefghijklmnopqrstuvwxyz234567';

    private const BASE62 = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    /**
     * @param string $prefix `token_prefix`
     * @param bool $checksum whether new tokens carry a checksum
     */
    public function __construct(private string $prefix, private bool $checksum = true)
    {
    }

    /**
     * A new random token.
     */
    public function generate(): GeneratedToken
    {
        $bytes  = random_bytes(LookupId::LENGTH);
        $lookup = '';
        for ($i = 0; $i < LookupId::LENGTH; ++$i) {
            $lookup .= self::BASE32[ord($bytes[$i]) & 31];
        }
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $body   = $this->prefix . $lookup . '_' . $secret;

        return new GeneratedToken(new LookupId($lookup), $this->checksum ? $body . self::checksum($body) : $body);
    }

    /**
     * The lookup id of a well-formed token, or null (malformed: wrong length, prefix, charset or checksum). No I/O.
     */
    public function parse(#[SensitiveParameter] string $presented): ?LookupId
    {
        if (strlen($presented) > self::MAX_LENGTH) {
            return null;
        }
        $pattern = '/^' . preg_quote($this->prefix, '/') . '([a-z2-7]{12})_[A-Za-z0-9_-]{43}([0-9A-Za-z]{6})?$/D';
        if (preg_match($pattern, $presented, $matches) !== 1) {
            return null;
        }
        if (isset($matches[2])) {
            $body = substr($presented, 0, -6);
            if (!hash_equals(self::checksum($body), $matches[2])) {
                return null;
            }
        }

        return new LookupId($matches[1]);
    }

    /**
     * CRC32 of the body in base62, left-padded to 6 characters.
     */
    public static function checksum(#[SensitiveParameter] string $body): string
    {
        $value  = crc32($body);
        $result = '';
        do {
            $result = self::BASE62[$value % 62] . $result;
            $value  = intdiv($value, 62);
        } while ($value > 0);

        return str_pad($result, 6, '0', STR_PAD_LEFT);
    }
}
