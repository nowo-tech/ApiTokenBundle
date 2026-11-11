<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Unit\Contract;

use DateTimeImmutable;
use LogicException;
use Nowo\ApiTokenBundle\Application\Token\GeneratedToken;
use Nowo\ApiTokenBundle\Contract\ApiTokenView;
use Nowo\ApiTokenBundle\Contract\Exception\ApiTokenIssueRefusedException;
use Nowo\ApiTokenBundle\Contract\Exception\InvalidApiTokenArgumentException;
use Nowo\ApiTokenBundle\Contract\Grants;
use Nowo\ApiTokenBundle\Contract\IssuedToken;
use Nowo\ApiTokenBundle\Contract\LookupId;
use Nowo\ApiTokenBundle\Contract\OwnerRef;
use Nowo\ApiTokenBundle\Contract\Reach;
use Nowo\ApiTokenBundle\Contract\ReachChoice;
use Nowo\ApiTokenBundle\Contract\SensitiveValue;
use Nowo\ApiTokenBundle\Contract\TokenId;
use Nowo\ApiTokenBundle\Domain\ApiToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

use function print_r;
use function str_repeat;
use function strlen;
use function var_export;

final class ValueObjectsTest extends TestCase
{
    public function testOwnerRefComparesExactly(): void
    {
        $a = new OwnerRef('user', 'UserA');

        self::assertSame('user:UserA', $a->key());
        self::assertTrue($a->equals(new OwnerRef('user', 'UserA')));
        self::assertFalse($a->equals(new OwnerRef('user', 'usera')));
        self::assertFalse($a->equals(new OwnerRef('member', 'UserA')));
        self::assertSame(' padded ', (new OwnerRef('user', ' padded '))->id, 'never trimmed');
        self::assertSame(str_repeat('é', 128), (new OwnerRef('user', str_repeat('é', 128)))->id, '128 characters, not bytes');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidOwners(): iterable
    {
        yield 'empty id' => ['user', ''];
        yield 'long id' => ['user', str_repeat('a', 129)];
        yield 'control char' => ['user', "a\nb"];
        yield 'NUL' => ['user', "a\0b"];
        yield 'invalid utf-8' => ['user', "\xff"];
        yield 'upper type' => ['User', '1'];
        yield 'type starts with digit' => ['1user', '1'];
        yield 'long type' => [str_repeat('a', 33), '1'];
        yield 'type with newline' => ["user\n", '1'];
    }

    #[DataProvider('invalidOwners')]
    public function testOwnerRefRefusesInvalidValues(string $type, string $id): void
    {
        $this->expectException(InvalidApiTokenArgumentException::class);
        new OwnerRef($type, $id);
    }

    public function testTokenId(): void
    {
        $id = new TokenId('01933b1c-5d2e-7a3b-8c4d-123456789abc');

        self::assertSame('01933b1c-5d2e-7a3b-8c4d-123456789abc', (string) $id);
        self::assertTrue($id->equals(new TokenId('01933b1c-5d2e-7a3b-8c4d-123456789abc')));
        self::assertFalse($id->equals(new TokenId('01933b1c-5d2e-7a3b-8c4d-123456789abd')));

        $this->expectException(InvalidApiTokenArgumentException::class);
        new TokenId('01933B1C-5D2E-7A3B-8C4D-123456789ABC');
    }

    public function testLookupId(): void
    {
        self::assertSame('abcdefgh2345', (string) new LookupId('abcdefgh2345'));

        $this->expectException(InvalidApiTokenArgumentException::class);
        new LookupId('abcdefgh2341');
    }

    public function testReachChoice(): void
    {
        $choice = new ReachChoice('wiki:docs', 'Docs', 'Wikis');
        self::assertSame(['wiki:docs', 'Docs', 'Wikis'], [$choice->key, $choice->label, $choice->group]);

        $this->expectException(InvalidApiTokenArgumentException::class);
        new ReachChoice('bad entry', 'x');
    }

    public function testGrantsShapeAndQueries(): void
    {
        $grants = new Grants(['tasks' => 'read', 'mcp' => 'on'], ['mcp' => ['destructive', 'destructive'], 'wiki' => []]);

        self::assertSame(['mcp' => 'on', 'tasks' => 'read'], $grants->levels, 'canonical module-key order');
        self::assertSame(['mcp' => ['destructive']], $grants->flags);
        self::assertSame('read', $grants->level('tasks'));
        self::assertNull($grants->level('wiki'));
        self::assertTrue($grants->hasFlag('mcp', 'destructive'));
        self::assertFalse($grants->hasFlag('tasks', 'destructive'));
        self::assertFalse((new Grants([], ['mcp' => ['destructive']]))->hasFlag('mcp', 'destructive'), 'a flag without a level grants nothing');
        self::assertSame(['levels' => $grants->levels, 'flags' => $grants->flags], $grants->toArray());
        self::assertFalse($grants->isEmpty());
        self::assertTrue((new Grants([]))->isEmpty());
    }

    /**
     * @return iterable<string, array{array<mixed>, array<mixed>}>
     */
    public static function invalidGrants(): iterable
    {
        yield 'int level' => [['tasks' => 1], []];
        yield 'int module' => [[0 => 'read'], []];
        yield 'flags not list' => [[], ['mcp' => 'destructive']];
        yield 'int flag module' => [[], [0 => ['x']]];
        yield 'int flag' => [[], ['mcp' => [1]]];
    }

    /**
     * @param array<mixed> $levels
     * @param array<mixed> $flags
     */
    #[DataProvider('invalidGrants')]
    public function testGrantsRefuseBadShapes(array $levels, array $flags): void
    {
        $this->expectException(InvalidApiTokenArgumentException::class);
        new Grants($this->untyped($levels), $this->untyped($flags));
    }

    public function testGrantsFromStoredDropsDamage(): void
    {
        $grants = Grants::fromStored(['levels' => ['tasks' => 'read', 'x' => 1, 3 => 'y'], 'flags' => ['mcp' => ['destructive', 2], 'bad' => 'no', 4 => ['z']]]);

        self::assertSame(['tasks' => 'read'], $grants->levels);
        self::assertSame(['mcp' => ['destructive']], $grants->flags);
        self::assertTrue(Grants::fromStored('nope')->isEmpty());
        self::assertTrue(Grants::fromStored(['levels' => 'x'])->isEmpty());
    }

    public function testReach(): void
    {
        $everything = Reach::everything(['org-1']);
        self::assertTrue($everything->isEverything());
        self::assertSame([], $everything->entries());
        self::assertSame(['org-1'], $everything->exceptions());
        self::assertSame('everything', $everything->summary());
        self::assertSame(['org-1', 'wiki:x'], $everything->without(['wiki:x', 'org-1'])->storedEntries());
        self::assertSame(['x'], $everything->without(['wiki:x'])->exceptions('wiki'));
        self::assertSame([], $everything->allEntries());

        $limited = Reach::limitedTo(['p1', 'wiki:docs', 'board:7', 'p1']);
        self::assertFalse($limited->isEverything());
        self::assertSame(['p1'], $limited->entries());
        self::assertSame(['docs'], $limited->entries('wiki'));
        self::assertSame(['7'], $limited->entries('board'));
        self::assertSame([], $limited->exceptions());
        self::assertSame(3, $limited->summary());
        self::assertSame(['p1', 'board:7'], $limited->without(['wiki:docs'])->allEntries());
        self::assertSame(['p1', 'wiki:docs', 'board:7'], $limited->storedEntries());
        self::assertSame([], Reach::limitedTo([])->allEntries(), 'an empty list reaches nothing');
    }

    public function testReachRefusesInvalidEntries(): void
    {
        $this->expectException(InvalidApiTokenArgumentException::class);
        Reach::limitedTo(['ok', 'not ok']);
    }

    public function testReachRefusesTooManyEntries(): void
    {
        $entries = [];
        for ($i = 0; $i <= Reach::MAX_ENTRIES; ++$i) {
            $entries[] = 'e' . $i;
        }
        $this->expectException(InvalidApiTokenArgumentException::class);
        Reach::limitedTo($entries);
    }

    public function testReachFromStoredFailsClosed(): void
    {
        self::assertSame(['a'], Reach::fromStored(false, ['a', 'bad entry', 3])->allEntries());
        self::assertFalse(Reach::fromStored(true, ['bad entry'])->isEverything(), 'damaged exceptions: reaches nothing');
        self::assertSame([], Reach::fromStored(true, ['bad entry'])->allEntries());
        self::assertFalse(Reach::fromStored(true, 'not a list')->isEverything());
        self::assertTrue(Reach::fromStored(true, ['x'])->isEverything());
        $many = [];
        for ($i = 0; $i <= Reach::MAX_ENTRIES; ++$i) {
            $many[] = 'e' . $i;
        }
        self::assertSame([], Reach::fromStored(false, $many)->allEntries());
    }

    public function testIssuedTokenNeverPrintsTheSecret(): void
    {
        $marker = 'REDACTED-TEST-marker';
        $view   = new ApiTokenView(new TokenId('01933b1c-5d2e-7a3b-8c4d-123456789abc'), new LookupId('abcdefgh2345'), new OwnerRef('user', '1'), 'n', new Grants([]), Reach::everything(), new DateTimeImmutable(), new DateTimeImmutable(), null, 'cli');
        $issued = new IssuedToken($view, $marker);

        self::assertSame($marker, $issued->secret());
        $generated = new GeneratedToken(new LookupId('abcdefgh2345'), $marker);
        self::assertSame($marker, $generated->token());

        // Audit LW-L1: the six channels a secret leaks through.
        foreach ([$issued, $generated, new SensitiveValue($marker)] as $value) {
            self::assertStringNotContainsString($marker, (string) json_encode($value), 'json_encode');
            self::assertStringContainsString('[redacted]', (string) json_encode($value));
            self::assertStringNotContainsString($marker, var_export($value, true), 'var_export');
            self::assertStringNotContainsString($marker, print_r((array) $value, true), '(array) cast');
            self::assertStringNotContainsString($marker, print_r($value, true), 'print_r');
            $dumper = new CliDumper();
            self::assertStringNotContainsString($marker, (string) $dumper->dump((new VarCloner())->cloneVar($value), true), 'VarDumper');
            try {
                serialize($value);
                self::fail('serialize must refuse');
            } catch (LogicException) {
            }
        }
        self::assertSame($marker, (new SensitiveValue($marker))->reveal());
        try {
            clone new SensitiveValue($marker);
            self::fail('clone must refuse');
        } catch (LogicException) {
        }
        self::assertSame(80, mb_strlen(ApiToken::nameKeyOf(str_repeat('İ', 80))), 'LW-D-L1: the key never grows');
        self::assertSame('deploy', ApiToken::nameKeyOf('DePloY'));
        foreach ([IssuedToken::class, GeneratedToken::class, SensitiveValue::class] as $class) {
            try {
                unserialize('O:' . strlen($class) . ':"' . $class . '":0:{}');
                self::fail('unserialize must refuse');
            } catch (LogicException) {
            }
        }
    }

    /**
     * Hands a deliberately ill-typed value to a typed constructor without silencing the analyser.
     */
    private function untyped(mixed $value): mixed
    {
        return $value;
    }

    public function testRefusalCarriesItsReason(): void
    {
        $refusal = new ApiTokenIssueRefusedException(ApiTokenIssueRefusedException::REACH, 'msg');
        self::assertSame('reach', $refusal->reason);
        self::assertSame('msg', $refusal->getMessage());
    }
}
