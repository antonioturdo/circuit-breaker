<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Tests\Unit\Counting;

use PHPUnit\Framework\TestCase;
use Zeusi\CircuitBreaker\Counting\DurationBands;
use Zeusi\CircuitBreaker\Counting\OutcomeCounts;
use Zeusi\CircuitBreaker\Exception\LogicException;
use Zeusi\CircuitBreaker\Outcome;
use Zeusi\CircuitBreaker\Tests\Fixtures\OutcomeCountsFactory;

final class OutcomeCountsTest extends TestCase
{
    public function testQueries(): void
    {
        // bands: without a duration, below 1 second, below 5 seconds, from 5 seconds
        $counts = OutcomeCountsFactory::create([1_000, 5_000], successes: [1, 2, 1, 0], failures: [1, 0, 1, 3]);

        self::assertSame(9, $counts->getOutcomes());
        self::assertSame(4, $counts->getOutcomes(Outcome::Success));
        self::assertSame(5, $counts->getOutcomes(Outcome::Failure));
        self::assertSame(7, $counts->getTimedOutcomes());
        self::assertSame(3, $counts->getTimedOutcomes(Outcome::Success));
        self::assertSame(5, $counts->getSlowOutcomes(1_000));
        self::assertSame(1, $counts->getSlowOutcomes(1_000, Outcome::Success));
        self::assertSame(3, $counts->getSlowOutcomes(5_000));
        self::assertSame(0, $counts->getSlowOutcomes(5_000, Outcome::Success));
    }

    public function testSlowOutcomesNeedAThresholdDelimitingTheBands(): void
    {
        $counts = OutcomeCountsFactory::create([1_000, 5_000], successes: [0, 0, 0, 0], failures: [0, 0, 0, 0]);

        $this->expectException(LogicException::class);
        $counts->getSlowOutcomes(2_000);
    }

    public function testOutcomesMustBeCountedInEveryBand(): void
    {
        $this->expectException(LogicException::class);

        new OutcomeCounts(new DurationBands([1_000]), [0, 0], [0, 0]);
    }
}
