<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zeusi\CircuitBreaker\Counting\DurationBand;
use Zeusi\CircuitBreaker\Outcome;
use Zeusi\CircuitBreaker\State;
use Zeusi\CircuitBreaker\Storage\CircuitState;
use Zeusi\CircuitBreaker\Storage\KeyScheme;

final class KeySchemeTest extends TestCase
{
    /**
     * @return iterable<string, array{CircuitState, string}>
     */
    public static function periodProvider(): iterable
    {
        yield 'initial' => [new CircuitState(State::Closed, 0), 'cb:v1:{payments}:c:0'];
        yield 'closed' => [new CircuitState(State::Closed, 1_791_676_800_000), 'cb:v1:{payments}:c:mv323uo0'];
        yield 'open' => [new CircuitState(State::Open, 1_791_676_800_123), 'cb:v1:{payments}:o:mv323urf'];
        yield 'half-open' => [new CircuitState(State::HalfOpen, 1_791_676_810_123), 'cb:v1:{payments}:h:mv3242h7'];
        yield 'largest time' => [new CircuitState(State::HalfOpen, (1 << 42) - 1), 'cb:v1:{payments}:h:1k4fnc6pr'];
    }

    #[DataProvider('periodProvider')]
    public function testKeys(CircuitState $state, string $expectedPeriodPrefix): void
    {
        $keyScheme = new KeyScheme('payments');

        self::assertSame('cb:v1:{payments}:state', $keyScheme->getStateKey());
        self::assertSame($expectedPeriodPrefix . ':calls', $keyScheme->getHalfOpenCallsKey($state));
        self::assertSame($expectedPeriodPrefix . ':s:tmpuoa', $keyScheme->getOutcomeKey($state, Outcome::Success, new DurationBand(null, null), 1_791_676_810));
        self::assertSame($expectedPeriodPrefix . ':f0-1jk:tmpuoa', $keyScheme->getOutcomeKey($state, Outcome::Failure, new DurationBand(0, 2_000), 1_791_676_810));
        self::assertSame($expectedPeriodPrefix . ':s1jk-:tmpuoa', $keyScheme->getOutcomeKey($state, Outcome::Success, new DurationBand(2_000, null), 1_791_676_810));
        self::assertSame($expectedPeriodPrefix . ':srs-1jk:tmpuoa', $keyScheme->getOutcomeKey($state, Outcome::Success, new DurationBand(1_000, 2_000), 1_791_676_810));
    }

    public function testPeriodsOfDifferentStatesStartingTogetherAreApart(): void
    {
        $keyScheme = new KeyScheme('payments');
        $closed = new CircuitState(State::Closed, 1_791_676_800_000);
        $halfOpen = new CircuitState(State::HalfOpen, 1_791_676_800_000);

        self::assertNotSame($keyScheme->getOutcomeKey($closed, Outcome::Failure, new DurationBand(null, null), 1), $keyScheme->getOutcomeKey($halfOpen, Outcome::Failure, new DurationBand(null, null), 1));
    }
}
