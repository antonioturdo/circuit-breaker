<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Opening;

use Zeusi\CircuitBreaker\Counting\OutcomeCounts;
use Zeusi\CircuitBreaker\Exception\InvalidArgumentException;
use Zeusi\CircuitBreaker\Outcome;
use Zeusi\CircuitBreaker\State;

/**
 * Opens the circuit when the rate of calls that failed or were slow in the time window reaches a threshold.
 *
 * Fits when a slow call costs as much as a failed one, as with PHP-FPM, where both hold a worker. A call is slow when
 * it takes at least the slow call duration; a slow failure counts once. The rate is computed on all outcomes: a success
 * registered without a duration counts as not slow. While closed, the rule waits for a minimum number of outcomes;
 * while half-open it does not.
 */
final class FailureOrSlowCallRateRule implements OpeningRuleInterface
{
    /**
     * @param float $slowCallDuration seconds from which a call is slow, at least 1 millisecond
     * @param int   $threshold        percentage of failed or slow calls (1-100) that opens the circuit
     * @param int   $minimumRequests  calls required in the time window before the rate is evaluated, at least 1
     */
    public function __construct(
        public readonly float $slowCallDuration,
        public readonly int $threshold,
        public readonly int $minimumRequests,
    ) {
        if (!(is_finite($slowCallDuration) && $slowCallDuration >= 0.001)) {
            throw new InvalidArgumentException(\sprintf('The slow call duration must be at least 1 millisecond, %s seconds given', $slowCallDuration));
        }

        if ($threshold < 1 || $threshold > 100) {
            throw new InvalidArgumentException(\sprintf('The failed or slow call rate threshold must be between 1 and 100, %d given', $threshold));
        }

        if ($minimumRequests < 1) {
            throw new InvalidArgumentException(\sprintf('The minimum requests must be at least 1, %d given', $minimumRequests));
        }
    }

    /**
     * @internal used by the circuit breaker
     */
    public function getDurationThresholds(): array
    {
        return [$this->getSlowCallDurationInMilliseconds()];
    }

    /**
     * @internal used by the circuit breaker
     */
    public function findOpeningCause(OutcomeCounts $counts, State $state): ?OpeningCause
    {
        return RateEvaluator::findOpeningCause(
            $this,
            $counts->getOutcomes(Outcome::Failure) + $counts->getSlowOutcomes($this->getSlowCallDurationInMilliseconds(), Outcome::Success),
            $counts->getOutcomes(),
            $this->threshold,
            $this->minimumRequests,
            $state,
        );
    }

    /**
     * @return positive-int
     */
    private function getSlowCallDurationInMilliseconds(): int
    {
        // at least 1, since the slow call duration is at least 1 millisecond
        return max(1, (int) round($this->slowCallDuration * 1000));
    }
}
