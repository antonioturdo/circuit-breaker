<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Storage;

use Zeusi\CircuitBreaker\Counting\DurationBands;
use Zeusi\CircuitBreaker\Counting\OutcomeCounts;
use Zeusi\CircuitBreaker\Exception\StorageException;
use Zeusi\CircuitBreaker\Outcome;

/**
 * Counts the outcomes of the period spent in a state, in buckets, one storage key per bucket, outcome and duration band.
 *
 * Bound to the period at construction, a counter cannot record outcomes in one period and count them in another. The
 * window is split into 60 buckets of at least 1 second, numbered from the Unix epoch. A count covers the buckets
 * spanning the counted range, so the covered interval exceeds the range by less than one bucket.
 *
 * Every outcome is a single increment, of the key of its outcome and duration band: the counts queried from the
 * bands (failures, outcomes with a duration, slow outcomes) can never disagree with each other.
 *
 * @internal
 */
final class OutcomeCounter
{
    private const BUCKETS = 60;
    private const MIN_BUCKET_DURATION = 1000;

    private readonly int $bucketDuration;

    /**
     * @param CircuitState  $state         the state whose period the outcomes belong to
     * @param int           $window        milliseconds the outcomes must remain countable
     * @param DurationBands $durationBands the bands durations are counted in: a single one when no rule decides on
     *                                     durations, so that they cost no storage key
     */
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly KeyScheme $keyScheme,
        private readonly CircuitState $state,
        private readonly int $window,
        private readonly DurationBands $durationBands,
    ) {
        $this->bucketDuration = max(self::MIN_BUCKET_DURATION, intdiv($window + self::BUCKETS - 1, self::BUCKETS));
    }

    /**
     * @param int|null $duration milliseconds the call took, null when unknown
     * @param int      $now      Unix timestamp in milliseconds
     *
     * @throws StorageException
     */
    public function recordOutcome(Outcome $outcome, ?int $duration, int $now): void
    {
        // a bucket is read until one window after its end; never 0, which the storage does not accept
        $this->storage->increment(
            $this->keyScheme->getOutcomeKey($this->state, $outcome, $this->durationBands->getBand($duration), intdiv($now, $this->bucketDuration)),
            max(1, intdiv($this->window + 2 * $this->bucketDuration + 999, 1000)),
        );
    }

    /**
     * @param int $startTime Unix timestamp in milliseconds, included
     * @param int $endTime   Unix timestamp in milliseconds, included
     *
     * @throws StorageException
     */
    public function countOutcomes(int $startTime, int $endTime): OutcomeCounts
    {
        $bands = $this->durationBands->getBands();
        $cellsByKey = [];

        for ($bucket = intdiv($startTime, $this->bucketDuration); $bucket <= intdiv($endTime, $this->bucketDuration); $bucket++) {
            foreach ($bands as $bandIndex => $band) {
                foreach (Outcome::cases() as $outcome) {
                    $cellsByKey[$this->keyScheme->getOutcomeKey($this->state, $outcome, $band, $bucket)] = [$outcome, $bandIndex];
                }
            }
        }

        $successesByBand = array_fill(0, \count($bands), 0);
        $failuresByBand = $successesByBand;

        foreach ($this->storage->fetch(array_keys($cellsByKey)) as $key => $cellValue) {
            [$outcome, $bandIndex] = $cellsByKey[$key];

            if ($outcome === Outcome::Success) {
                $successesByBand[$bandIndex] += $cellValue;
            } else {
                $failuresByBand[$bandIndex] += $cellValue;
            }
        }

        return new OutcomeCounts($this->durationBands, array_values($successesByBand), array_values($failuresByBand));
    }
}
