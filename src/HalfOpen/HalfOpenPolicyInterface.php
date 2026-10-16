<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\HalfOpen;

/**
 * Decides how the circuit tests the service while half-open.
 *
 * The policy decides which calls go through and whether enough has been observed to close the circuit before the
 * end of the half-open state. Everything else is up to the circuit: it reopens as soon as the failure rate of the
 * half-open outcomes within the time window reaches the threshold, and it ends the half-open state when the maximum
 * duration has elapsed, whatever the policy says, so that the circuit breaker never causes an outage itself. When the
 * half-open state ends, early or not, the circuit closes if the failure rate is below the threshold or if no outcome
 * was registered.
 */
interface HalfOpenPolicyInterface
{
    /**
     * Decides whether a call goes through.
     */
    public function grantsPermission(PermissionRequest $request): bool;

    /**
     * Decides, after an outcome, whether enough has been observed to close the circuit.
     */
    public function allowsEarlyClosing(HalfOpenProgress $progress): bool;

    /**
     * Seconds the half-open state lasts at most.
     */
    public function getMaxDuration(): float;
}
