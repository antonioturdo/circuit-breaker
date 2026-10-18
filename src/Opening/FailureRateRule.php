<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Opening;

use Zeusi\CircuitBreaker\Counting\OutcomeCounts;
use Zeusi\CircuitBreaker\Exception\InvalidArgumentException;
use Zeusi\CircuitBreaker\Outcome;
use Zeusi\CircuitBreaker\State;

/**
 * Opens the circuit when the failure rate of the time window reaches a threshold.
 *
 * While closed, the rule waits for a minimum number of outcomes in the time window. While half-open it does not: the
 * circuit reopens as soon as the failure rate of the half-open outcomes reaches the threshold.
 */
final class FailureRateRule implements OpeningRuleInterface
{
    /**
     * @param int $threshold       percentage of failed calls (1-100) that opens the circuit
     * @param int $minimumRequests calls required in the time window before the failure rate is evaluated, at least 1
     */
    public function __construct(
        public readonly int $threshold,
        public readonly int $minimumRequests,
    ) {
        if ($threshold < 1 || $threshold > 100) {
            throw new InvalidArgumentException(\sprintf('The failure rate threshold must be between 1 and 100, %d given', $threshold));
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
        return [];
    }

    /**
     * @internal used by the circuit breaker
     */
    public function findOpeningCause(OutcomeCounts $counts, State $state): ?OpeningCause
    {
        return RateEvaluator::findOpeningCause($this, $counts->getOutcomes(Outcome::Failure), $counts->getOutcomes(), $this->threshold, $this->minimumRequests, $state);
    }
}
