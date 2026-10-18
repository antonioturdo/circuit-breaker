<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker;

use Zeusi\CircuitBreaker\Exception\InvalidArgumentException;
use Zeusi\CircuitBreaker\HalfOpen\HalfOpenPolicyInterface;
use Zeusi\CircuitBreaker\Opening\FailureOrSlowCallRateRule;
use Zeusi\CircuitBreaker\Opening\FailureRateRule;
use Zeusi\CircuitBreaker\Opening\OpeningRuleInterface;
use Zeusi\CircuitBreaker\Opening\SlowCallRateRule;

/**
 * Configuration of a circuit breaker.
 *
 * Durations are in seconds, with millisecond precision: 1 millisecond is the smallest positive duration.
 */
final class Configuration
{
    // the TTL of the circuit state: a longer open or half-open state would expire and become closed
    private const MAX_DURATION = 2_592_000.0;

    /**
     * @param float                   $timeWindow           seconds over which calls are counted, from 10 to 3600
     * @param list<OpeningRuleInterface> $openingRules         rules opening the circuit, at least one: the circuit opens when any of them requires it
     * @param float                   $openDuration         seconds the circuit stays open before becoming half-open, from 1 second to 30 days
     * @param HalfOpenPolicyInterface $halfOpenPolicy       how the half-open state tests the service, lasting at most from 1 second to 30 days
     */
    public function __construct(
        public readonly float $timeWindow,
        public readonly array $openingRules,
        public readonly float $openDuration,
        public readonly HalfOpenPolicyInterface $halfOpenPolicy,
    ) {
        // outcomes are counted in buckets of at least 1 second: shorter windows would have too few buckets, and
        // the count would approach a fixed window aligned to the clock
        if ($timeWindow < 10.0) {
            throw new InvalidArgumentException(\sprintf('The time window must be at least 10 seconds, %s seconds given', $timeWindow));
        }

        // longer windows dilute a recent outage in past successes, so that the circuit opens late or never
        if ($timeWindow > 3600.0) {
            throw new InvalidArgumentException(\sprintf('The time window must be at most 1 hour, %s seconds given', $timeWindow));
        }

        if ($openingRules === []) {
            throw new InvalidArgumentException('At least one opening rule is required');
        }

        foreach ($openingRules as $openingRule) {
            // @phpstan-ignore instanceof.alwaysTrue (checked for applications without static analysis)
            if (!$openingRule instanceof OpeningRuleInterface) {
                throw new InvalidArgumentException(\sprintf('The opening rules must be %s, %s or %s instances, %s given', FailureRateRule::class, SlowCallRateRule::class, FailureOrSlowCallRateRule::class, get_debug_type($openingRule)));
            }
        }

        // shorter open durations barely protect the service, and with a dead service they make the circuit cycle so
        // fast that every cycle creates new storage keys
        if (!($openDuration >= 1.0 && $openDuration <= self::MAX_DURATION)) {
            throw new InvalidArgumentException(\sprintf('The open duration must be between 1 second and 30 days, %s seconds given', $openDuration));
        }

        // shorter half-open states end before the outcomes of their calls arrive, and close without testing the
        // service; checked here too, for the policies of the application
        $halfOpenMaxDuration = $halfOpenPolicy->getMaxDuration();

        if (!($halfOpenMaxDuration >= 1.0 && $halfOpenMaxDuration <= self::MAX_DURATION)) {
            throw new InvalidArgumentException(\sprintf('The maximum duration of the half-open state must be between 1 second and 30 days, %s seconds given', $halfOpenMaxDuration));
        }
    }
}
