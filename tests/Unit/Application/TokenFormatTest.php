<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Unit\Application;

use LogicException;
use Nowo\ApiTokenBundle\Application\Token\TokenCodec;
use Nowo\ApiTokenBundle\Application\Token\TokenHasher;
use Nowo\ApiTokenBundle\Domain\ApiToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function hash;
use function hash_hmac;
use function preg_match;
use function print_r;
use function str_repeat;
use function strlen;
use function strtoupper;
use function substr;

/**
 * Token format (contract §6.1) and its parser fuzz table (§11 "Format"): every malformed value is refused without I/O.
 */
final class TokenFormatTest extends TestCase
{
    public function testGeneratedTokensHaveTheContractShape(): void
    {
        $codec = new TokenCodec('nwt_');
        $seen  = [];
        for ($i = 0; $i < 200; ++$i) {
            $generated = $codec->generate();
            self::assertSame(1, preg_match('/^nwt_([a-z2-7]{12})_[A-Za-z0-9_-]{43}[0-9A-Za-z]{6}$/D', $generated->token, $m));
            self::assertSame($m[1], $generated->lookupId->value);
            self::assertSame($generated->lookupId->value, $codec->parse($generated->token)?->value);
            $seen[$generated->token] = true;
        }
        self::assertCount(200, $seen, 'tokens are random');
        self::assertStringNotContainsString($generated->token, print_r($generated, true));
    }

    public function testWithoutChecksum(): void
    {
        $codec     = new TokenCodec('bt_', false);
        $generated = $codec->generate();

        self::assertSame(3 + 12 + 1 + 43, strlen($generated->token));
        self::assertSame($generated->lookupId->value, $codec->parse($generated->token)?->value);
    }

    public function testChecksumIsSixBase62Characters(): void
    {
        self::assertSame('000000', TokenCodec::checksum(''));
        self::assertSame(1, preg_match('/^[0-9A-Za-z]{6}$/D', TokenCodec::checksum('nwt_abcdefgh2345_x')));
        // crc32('a') = 3904355907 = 4 * 62^5 + ... : still six characters
        self::assertSame(6, strlen(TokenCodec::checksum('a')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformed(): iterable
    {
        $codec = new TokenCodec('nwt_');
        $valid = $codec->generate()->token;
        $body  = substr($valid, 0, -6);

        yield 'empty' => [''];
        yield 'too long' => [$valid . str_repeat('a', 128)];
        yield 'wrong prefix' => ['bt_' . substr($valid, 4)];
        yield 'prefix upper case' => [strtoupper('nwt_') . substr($valid, 4)];
        yield 'lookup upper case' => ['nwt_' . strtoupper(substr($valid, 4, 12)) . substr($valid, 16)];
        yield 'lookup with 1' => ['nwt_1' . substr($valid, 5)];
        yield 'short secret' => [substr($body, 0, -1) . TokenCodec::checksum(substr($body, 0, -1))];
        yield 'wrong checksum' => [$body . ($valid[strlen($valid) - 1] === 'a' ? substr($valid, -6, 5) . 'b' : substr($valid, -6, 5) . 'a')];
        yield 'trailing newline' => [$valid . "\n"];
        yield 'leading space' => [' ' . $valid];
        yield 'NUL inside' => [substr($valid, 0, 20) . "\0" . substr($valid, 21)];
        yield 'unicode look-alike' => ['nwt_а' . substr($valid, 5)];
        yield 'missing separator' => ['nwt_' . substr($valid, 4, 12) . substr($valid, 17)];
        yield 'base64 padding' => [substr($body, 0, -1) . '='];
        yield 'checksum too short' => [$body . 'abc'];
    }

    #[DataProvider('malformed')]
    public function testMalformedValuesAreRefused(string $presented): void
    {
        self::assertNull((new TokenCodec('nwt_'))->parse($presented));
    }

    public function testHasherVersions(): void
    {
        $token = 'nwt_REDACTED-TEST';
        $v1    = new TokenHasher();
        self::assertSame(ApiToken::HASH_SHA256, $v1->currentVersion());
        self::assertSame(hash('sha256', $token), $v1->hash($token));
        self::assertTrue($v1->matches($token, hash('sha256', $token), 1));
        self::assertFalse($v1->matches($token . 'x', hash('sha256', $token), 1));
        self::assertFalse($v1->matches($token, hash('sha256', $token), 2), 'version 2 without a pepper never matches');
        self::assertFalse($v1->matches($token, hash('sha256', 'unknown-version:' . $token), 9), 'unknown version never matches');

        $pepper = 'pepper-REDACTED-TEST';
        $v2     = new TokenHasher(2, $pepper);
        self::assertSame(2, $v2->currentVersion());
        self::assertSame(hash_hmac('sha256', $token, $pepper), $v2->hash($token));
        self::assertTrue($v2->matches($token, hash_hmac('sha256', $token, $pepper), 2));
        self::assertTrue($v2->matches($token, hash('sha256', $token), 1), 'rows hashed before the switch keep working');
    }

    public function testVersionTwoNeedsAPepper(): void
    {
        $this->expectException(LogicException::class);
        new TokenHasher(2, '');
    }
}
