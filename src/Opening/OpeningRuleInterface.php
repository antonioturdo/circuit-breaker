<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Opening;

use Zeusi\CircuitBreaker\Counting\OutcomeCounts;
use Zeusi\CircuitBreaker\State;

/**
 * A rule opening the circuit on the outcomes of the time window.
 *
 * @internal implemented by the rules of the library only: the counts a rule decides on are not a stable API yet
 */
interface OpeningRuleInterface
{
    /**
     * Gets the duration thresholds the rule decides on, declared once: outcomes are counted in the bands they delimit.
     *
     * @return list<positive-int> milliseconds
     */
    public function getDurationThresholds(): array;

    /**
     * Finds whether the outcomes counted in the given state require the circuit to open.
     *
     * While half-open the minimum number of outcomes does not apply: the circuit reopens on the first evidence.
     */
    public function findOpeningCause(OutcomeCounts $counts, State $state): ?OpeningCause;
}
