<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Opening;

/**
 * The opening rule that required the circuit to open, with the outcomes it evaluated.
 */
final class OpeningCause
{
    /**
     * @param OpeningRuleInterface $openingRule       the rule, as configured: a FailureRateRule, a SlowCallRateRule or a
     *                                                FailureOrSlowCallRateRule
     * @param int                  $matchingOutcomes  outcomes matching the rule
     * @param int                  $evaluatedOutcomes outcomes the rate was computed on
     */
    public function __construct(
        public readonly OpeningRuleInterface $openingRule,
        public readonly int $matchingOutcomes,
        public readonly int $evaluatedOutcomes,
    ) {}
}
