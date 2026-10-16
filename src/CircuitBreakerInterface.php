<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker;

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

    public function registerSuccess(): void;

    public function registerFailure(): void;

    public function getState(): State;
}
