<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Counting;

/**
 * The duration bands outcomes are counted in, delimited by the duration thresholds of the opening rules.
 *
 * Without thresholds durations are not counted: a single band without bounds holds every outcome. With thresholds the
 * band without bounds holds the outcomes registered without a duration, and the others fall between two consecutive
 * thresholds, so that every outcome is counted in exactly one band. With thresholds of 1 and 2 seconds, the bands are:
 * without a duration, [0, 1000), [1000, 2000) and [2000, infinity).
 *
 * @internal
 */
final class DurationBands
{
    private readonly DurationBand $unboundedBand;

    /** @var list<DurationBand> by increasing durations, the first one from 0; none without thresholds */
    private readonly array $timedBands;

    /**
     * @param list<positive-int> $thresholds milliseconds, in any order and possibly repeated
     */
    public function __construct(array $thresholds)
    {
        $thresholds = array_values(array_unique($thresholds));
        sort($thresholds);

        $timedBands = [];
        $lowerBound = 0;

        foreach ($thresholds as $threshold) {
            $timedBands[] = new DurationBand($lowerBound, $threshold);
            $lowerBound = $threshold;
        }

        if ($thresholds !== []) {
            $timedBands[] = new DurationBand($lowerBound, null);
        }

        $this->unboundedBand = new DurationBand(null, null);
        $this->timedBands = $timedBands;
    }

    /**
     * @return list<DurationBand> the band without bounds first, then the others by increasing durations
     */
    public function getBands(): array
    {
        return [$this->unboundedBand, ...$this->timedBands];
    }

    /**
     * Gets the band an outcome is counted in.
     *
     * @param int|null $duration milliseconds the call took, null when unknown
     */
    public function getBand(?int $duration): DurationBand
    {
        if ($duration === null || $this->timedBands === []) {
            return $this->unboundedBand;
        }

        // the last band starting at or before the duration
        $band = $this->timedBands[0];

        foreach ($this->timedBands as $timedBand) {
            if ($duration >= $timedBand->lowerBound) {
                $band = $timedBand;
            }
        }

        return $band;
    }
}
