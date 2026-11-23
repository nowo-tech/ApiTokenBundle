<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Unit\Application;

use ArrayObject;
use DateTimeImmutable;
use Nowo\ApiTokenBundle\Application\Service\ReachFilterChain;
use Nowo\ApiTokenBundle\Contract\ApiTokenPrincipal;
use Nowo\ApiTokenBundle\Contract\Grants;
use Nowo\ApiTokenBundle\Contract\OwnerRef;
use Nowo\ApiTokenBundle\Contract\Reach;
use Nowo\ApiTokenBundle\Contract\ReachFilterInterface;
use Nowo\ApiTokenBundle\Contract\TokenId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * The reach filter chain only narrows (contract §6.5, §11 "Reach: the filter chain only narrows").
 */
final class ReachFilterChainTest extends TestCase
{
    /**
     * @return iterable<string, array{Reach, Reach, bool, list<string>}>
     */
    public static function clamps(): iterable
    {
        yield 'limited, filter drops one' => [Reach::limitedTo(['a', 'b']), Reach::limitedTo(['a']), false, ['a']];
        yield 'limited, filter adds one' => [Reach::limitedTo(['a']), Reach::limitedTo(['a', 'z']), false, ['a']];
        yield 'limited, filter answers everything' => [Reach::limitedTo(['a', 'b']), Reach::everything(), false, ['a', 'b']];
        yield 'limited, filter answers everything but b' => [Reach::limitedTo(['a', 'b']), Reach::everything(['b']), false, ['a']];
        yield 'everything, filter adds an exception' => [Reach::everything(['x']), Reach::everything(['y']), true, ['x', 'y']];
        yield 'everything, filter drops an exception' => [Reach::everything(['x']), Reach::everything(), true, ['x']];
        yield 'everything, filter limits' => [Reach::everything(['x']), Reach::limitedTo(['x', 'p1']), false, ['p1']];
        yield 'nothing stays nothing' => [Reach::limitedTo([]), Reach::everything(), false, []];
    }

    /**
     * @param list<string> $entries
     */
    #[DataProvider('clamps')]
    public function testAnAnswerIsKeptInsideTheReachItWasGiven(Reach $before, Reach $after, bool $everything, array $entries): void
    {
        $clamped = ReachFilterChain::clamp($before, $after);

        self::assertSame($everything, $clamped->isEverything());
        self::assertSame($entries, $clamped->storedEntries());
    }

    public function testTheChainRunsEveryFilterInOrderOnTheNarrowedReach(): void
    {
        /** @var ArrayObject<int, list<string>> $seen */
        $seen   = new ArrayObject();
        $first  = $this->filter(static fn (Reach $reach): Reach => $reach->without(['b']), $seen);
        $second = $this->filter(static fn (): Reach => Reach::limitedTo(['a', 'b', 'c']), $seen);
        $chain  = new ReachFilterChain([$first, $second]);

        $reach = $chain->apply($this->principal(Reach::limitedTo(['a', 'b'])));

        self::assertSame(['a'], $reach->storedEntries(), 'the second filter cannot bring b back or add c');
        self::assertSame([['a', 'b'], ['a']], $seen->getArrayCopy());
    }

    public function testNoFilterKeepsTheStoredReach(): void
    {
        $stored = Reach::everything(['x']);

        self::assertSame($stored, (new ReachFilterChain([]))->apply($this->principal($stored)));
    }

    /**
     * @param callable(Reach): Reach $answer
     * @param ArrayObject<int, list<string>> $seen
     */
    private function filter(callable $answer, ArrayObject $seen): ReachFilterInterface
    {
        return new class($answer, $seen) implements ReachFilterInterface {
            /** @var callable(Reach): Reach */
            private $answer;

            /**
             * @param callable(Reach): Reach $answer
             * @param ArrayObject<int, list<string>> $seen
             */
            public function __construct(callable $answer, private readonly ArrayObject $seen)
            {
                $this->answer = $answer;
            }

            public function filter(ApiTokenPrincipal $principal, Reach $reach): Reach
            {
                $this->seen[] = $reach->storedEntries();

                return ($this->answer)($reach);
            }
        };
    }

    private function principal(Reach $reach): ApiTokenPrincipal
    {
        return new ApiTokenPrincipal(new TokenId('01935f7e-0000-7000-8000-000000000001'), 'CI', new OwnerRef('user', '1'), new stdClass(), new Grants([]), $reach, new DateTimeImmutable('2027-01-01'));
    }
}
