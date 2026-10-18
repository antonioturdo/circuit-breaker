<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Opening;

use Zeusi\CircuitBreaker\State;

/**
 * Evaluates the rate of an opening rule against its threshold and minimum.
 *
 * @internal
 */
final class RateEvaluator
{
    /**
     * Finds the opening cause when the rate of the matching outcomes reaches the threshold, with enough evaluated outcomes.
     *
     * While half-open the minimum number of outcomes does not apply: a single evaluated outcome is enough.
     *
     * @param int $threshold       percentage (1-100) of matching outcomes that opens the circuit
     * @param int $minimumRequests evaluated outcomes required while closed
     */
    public static function findOpeningCause(
        OpeningRuleInterface $openingRule,
        int $matchingOutcomes,
        int $evaluatedOutcomes,
        int $threshold,
        int $minimumRequests,
        State $state,
    ): ?OpeningCause {
        $hasEnoughOutcomes = $state === State::HalfOpen ? $evaluatedOutcomes > 0 : $evaluatedOutcomes >= $minimumRequests;

        if (!$hasEnoughOutcomes || $matchingOutcomes * 100 < $threshold * $evaluatedOutcomes) {
            return null;
        }

        return new OpeningCause($openingRule, $matchingOutcomes, $evaluatedOutcomes);
    }
}
