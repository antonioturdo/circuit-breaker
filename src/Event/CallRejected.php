<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Event;

use Zeusi\CircuitBreaker\State;

/**
 * A call was rejected: the circuit was open, or half-open and the policy did not grant permission.
 */
final class CallRejected implements EventInterface
{
    /**
     * @param State $state open or half-open
     */
    public function __construct(
        private readonly string $service,
        public readonly State $state,
    ) {}

    public function getService(): string
    {
        return $this->service;
    }
}
