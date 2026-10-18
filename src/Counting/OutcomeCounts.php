<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Counting;

use Zeusi\CircuitBreaker\Exception\LogicException;
use Zeusi\CircuitBreaker\Outcome;

/**
 * The outcomes counted in a time range, by outcome and duration band, queried by the opening rules.
 *
 * @internal
 */
final class OutcomeCounts
{
    /** @var list<DurationBand> */
    private readonly array $bands;

    /**
     * @param list<int> $successesByBand successes in each band of the duration bands, in the same order
     * @param list<int> $failuresByBand  failures in each band of the duration bands, in the same order
     */
    public function __construct(
        DurationBands $durationBands,
        private readonly array $successesByBand,
        private readonly array $failuresByBand,
    ) {
        $this->bands = $durationBands->getBands();

        if (\count($successesByBand) !== \count($this->bands) || \count($failuresByBand) !== \count($this->bands)) {
            throw new LogicException('The outcomes must be counted in every duration band');
        }
    }

    /**
     * Counts the outcomes, of both kinds when no outcome is given.
     */
    public function getOutcomes(?Outcome $outcome = null): int
    {
        return $this->sumBands(static fn(DurationBand $band): bool => true, $outcome);
    }

    /**
     * Counts the outcomes registered with a duration, of both kinds when no outcome is given.
     */
    public function getTimedOutcomes(?Outcome $outcome = null): int
    {
        return $this->sumBands(static fn(DurationBand $band): bool => $band->isTimed(), $outcome);
    }

    /**
     * Counts the outcomes lasting at least the given duration, of both kinds when no outcome is given.
     *
     * @param int $threshold milliseconds, a duration threshold delimiting the bands
     *
     * @throws LogicException if the threshold does not delimit the bands, which would split a band: a rule querying a
     *                        threshold it did not declare
     */
    public function getSlowOutcomes(int $threshold, ?Outcome $outcome = null): int
    {
        if ($threshold <= 0 || !\in_array($threshold, array_map(static fn(DurationBand $band): ?int => $band->lowerBound, $this->bands), true)) {
            throw new LogicException(\sprintf('The duration threshold %d ms does not delimit the duration bands: an opening rule must declare the thresholds it queries in getDurationThresholds()', $threshold));
        }

        return $this->sumBands(static fn(DurationBand $band): bool => $band->lowerBound >= $threshold, $outcome);
    }

    /**
     * @param \Closure(DurationBand): bool $isIncluded
     */
    private function sumBands(\Closure $isIncluded, ?Outcome $outcome): int
    {
        $sum = 0;

        foreach ($this->bands as $bandIndex => $band) {
            if (!$isIncluded($band)) {
                continue;
            }

            $sum += match ($outcome) {
                Outcome::Success => $this->successesByBand[$bandIndex],
                Outcome::Failure => $this->failuresByBand[$bandIndex],
                null => $this->successesByBand[$bandIndex] + $this->failuresByBand[$bandIndex],
            };
        }

        return $sum;
    }
}
