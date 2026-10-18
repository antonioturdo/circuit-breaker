<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Opening;

use Zeusi\CircuitBreaker\Counting\OutcomeCounts;
use Zeusi\CircuitBreaker\Exception\InvalidArgumentException;
use Zeusi\CircuitBreaker\State;

/**
 * Opens the circuit when the rate of slow calls in the time window reaches a threshold.
 *
 * A call is slow when it takes at least the slow call duration. The rate is computed on the outcomes registered with a
 * duration: outcomes without one are neither slow nor fast. A failure can be slow too, counting for both rules. While
 * closed, the rule waits for a minimum number of outcomes with a duration; while half-open it does not.
 */
final class SlowCallRateRule implements OpeningRuleInterface
{
    /**
     * @param float $slowCallDuration seconds from which a call is slow, at least 1 millisecond
     * @param int   $threshold        percentage of slow calls (1-100) that opens the circuit
     * @param int   $minimumRequests  calls with a duration required in the time window before the rate is evaluated, at least 1
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
            throw new InvalidArgumentException(\sprintf('The slow call rate threshold must be between 1 and 100, %d given', $threshold));
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
            $counts->getSlowOutcomes($this->getSlowCallDurationInMilliseconds()),
            $counts->getTimedOutcomes(),
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
