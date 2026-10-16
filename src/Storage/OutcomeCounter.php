<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Storage;

use Zeusi\CircuitBreaker\Exception\StorageException;
use Zeusi\CircuitBreaker\Outcome;

/**
 * Counts the outcomes of the period spent in a state, in buckets, one storage key per bucket and outcome.
 *
 * Bound to the period at construction, a counter cannot record outcomes in one period and count them in another. The
 * window is split into 60 buckets of at least 1 second, numbered from the Unix epoch. A count covers the buckets
 * spanning the counted range, so the covered interval exceeds the range by less than one bucket.
 *
 * @internal
 */
final class OutcomeCounter
{
    private const BUCKETS = 60;
    private const MIN_BUCKET_DURATION = 1000;

    private readonly int $bucketDuration;

    /**
     * @param CircuitState $state  the state whose period the outcomes belong to
     * @param int          $window milliseconds the outcomes must remain countable
     */
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly KeyScheme $keyScheme,
        private readonly CircuitState $state,
        private readonly int $window,
    ) {
        $this->bucketDuration = max(self::MIN_BUCKET_DURATION, intdiv($window + self::BUCKETS - 1, self::BUCKETS));
    }

    /**
     * @param int $now Unix timestamp in milliseconds
     *
     * @throws StorageException
     */
    public function recordOutcome(Outcome $outcome, int $now): void
    {
        // a bucket is read until one window after its end; never 0, which the storage does not accept
        $this->storage->increment(
            $this->keyScheme->getOutcomeKey($this->state, $outcome, intdiv($now, $this->bucketDuration)),
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
        $successKeys = [];
        $failureKeys = [];

        for ($bucket = intdiv($startTime, $this->bucketDuration); $bucket <= intdiv($endTime, $this->bucketDuration); $bucket++) {
            $successKeys[] = $this->keyScheme->getOutcomeKey($this->state, Outcome::Success, $bucket);
            $failureKeys[] = $this->keyScheme->getOutcomeKey($this->state, Outcome::Failure, $bucket);
        }

        $bucketValues = $this->storage->fetch([...$successKeys, ...$failureKeys]);

        return new OutcomeCounts(
            array_sum(array_intersect_key($bucketValues, array_flip($successKeys))),
            array_sum(array_intersect_key($bucketValues, array_flip($failureKeys))),
        );
    }

}
