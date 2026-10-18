<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Tests\Unit\Counting;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zeusi\CircuitBreaker\Counting\DurationBand;
use Zeusi\CircuitBreaker\Counting\DurationBands;

final class DurationBandsTest extends TestCase
{
    public function testBands(): void
    {
        $bands = new DurationBands([2_000, 1_000, 2_000]);

        self::assertEquals(
            [new DurationBand(null, null), new DurationBand(0, 1_000), new DurationBand(1_000, 2_000), new DurationBand(2_000, null)],
            $bands->getBands(),
        );
    }

    /**
     * @return iterable<string, array{list<positive-int>, int|null, DurationBand}>
     */
    public static function bandProvider(): iterable
    {
        yield 'no threshold, without a duration' => [[], null, new DurationBand(null, null)];
        yield 'no threshold, with a duration' => [[], 5_000, new DurationBand(null, null)];
        yield 'without a duration' => [[1_000, 2_000], null, new DurationBand(null, null)];
        yield 'instant call' => [[1_000, 2_000], 0, new DurationBand(0, 1_000)];
        yield 'just below a threshold' => [[1_000, 2_000], 999, new DurationBand(0, 1_000)];
        yield 'at a threshold' => [[1_000, 2_000], 1_000, new DurationBand(1_000, 2_000)];
        yield 'beyond the last threshold' => [[1_000, 2_000], 60_000, new DurationBand(2_000, null)];
    }

    /**
     * @param list<positive-int> $thresholds
     */
    #[DataProvider('bandProvider')]
    public function testBandOfAnOutcome(array $thresholds, ?int $duration, DurationBand $expectedBand): void
    {
        self::assertEquals($expectedBand, (new DurationBands($thresholds))->getBand($duration));
    }
}
