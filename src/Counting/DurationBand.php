<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Counting;

/**
 * A range of call durations outcomes are counted in.
 *
 * The band without bounds holds the outcomes registered without a duration, or every outcome when durations are not
 * counted.
 *
 * @internal
 */
final class DurationBand
{
    /**
     * @param int|null $lowerBound milliseconds, included; null for the band without bounds
     * @param int|null $upperBound milliseconds, excluded; null for the last band and for the band without bounds
     */
    public function __construct(
        public readonly ?int $lowerBound,
        public readonly ?int $upperBound,
    ) {}

    /**
     * Checks whether the band holds outcomes registered with a duration.
     */
    public function isTimed(): bool
    {
        return $this->lowerBound !== null;
    }
}
