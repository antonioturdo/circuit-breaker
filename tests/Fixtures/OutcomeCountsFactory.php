<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Tests\Fixtures;

use Zeusi\CircuitBreaker\Counting\DurationBands;
use Zeusi\CircuitBreaker\Counting\OutcomeCounts;

/**
 * Builds outcome counts as an outcome counter would.
 */
final class OutcomeCountsFactory
{
    public static function createWithoutDurations(int $successes, int $failures): OutcomeCounts
    {
        return new OutcomeCounts(new DurationBands([]), [$successes], [$failures]);
    }

    /**
     * Builds counts in the duration bands delimited by the thresholds: first without a duration, then by increasing
     * durations.
     *
     * @param list<positive-int> $thresholds milliseconds delimiting the duration bands
     * @param list<int>          $successes  successes in each band
     * @param list<int>          $failures   failures in each band
     */
    public static function create(array $thresholds, array $successes, array $failures): OutcomeCounts
    {
        return new OutcomeCounts(new DurationBands($thresholds), $successes, $failures);
    }
}
