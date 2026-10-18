<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker;

use Zeusi\CircuitBreaker\Exception\InvalidArgumentException;

/**
 * Circuit breaker protecting a single service.
 */
interface CircuitBreakerInterface
{
    public function getService(): string;

    /**
     * Acquires the permission to call the service, returning false when the call must be rejected.
     *
     * A granted permission must be followed by registerSuccess() or registerFailure(). In half-open state, only a ratio
     * of the calls is permitted. Use getState() to inspect the circuit.
     */
    public function acquirePermission(): bool;

    /**
     * Registers that the service answered.
     *
     * @param float|null $duration seconds the call took, for the opening rules deciding on durations
     *
     * @throws InvalidArgumentException if the duration is negative or not finite
     */
    public function registerSuccess(?float $duration = null): void;

    /**
     * Registers that the service did not answer, or answered with an error of its own.
     *
     * @param float|null $duration seconds the call took, for the opening rules deciding on durations
     *
     * @throws InvalidArgumentException if the duration is negative or not finite
     */
    public function registerFailure(?float $duration = null): void;

    public function getState(): State;
}
