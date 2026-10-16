<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker;

use Zeusi\CircuitBreaker\Exception\InvalidArgumentException;
use Zeusi\CircuitBreaker\HalfOpen\HalfOpenPolicyInterface;

/**
 * Configuration of the failure rate strategy.
 *
 * Durations are in seconds, with millisecond precision: 1 millisecond is the smallest positive duration.
 */
final class Configuration
{
    // the TTL of the circuit state: a longer open or half-open state would expire and become closed
    private const MAX_DURATION = 2_592_000.0;

    /**
     * @param float                   $timeWindow           seconds over which calls are counted, from 10 to 3600
     * @param int                     $failureRateThreshold percentage of failed calls (1-100) that opens the circuit
     * @param int                     $minimumRequests      calls required in the time window before the failure rate is evaluated
     * @param float                   $openDuration         seconds the circuit stays open before becoming half-open, from 1 second to 30 days
     * @param HalfOpenPolicyInterface $halfOpenPolicy       how the half-open state tests the service, lasting at most from 1 second to 30 days
     */
    public function __construct(
        public readonly float $timeWindow,
        public readonly int $failureRateThreshold,
        public readonly int $minimumRequests,
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

        if ($failureRateThreshold < 1 || $failureRateThreshold > 100) {
            throw new InvalidArgumentException(\sprintf('The failure rate threshold must be between 1 and 100, %d given', $failureRateThreshold));
        }

        if ($minimumRequests < 1) {
            throw new InvalidArgumentException(\sprintf('The minimum requests must be at least 1, %d given', $minimumRequests));
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
