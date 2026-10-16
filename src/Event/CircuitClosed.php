<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Event;

/**
 * The half-open policy allowed closing and the failure rate was below the threshold: the circuit closed.
 */
final class CircuitClosed implements EventInterface
{
    public function __construct(
        private readonly string $service,
    ) {}

    public function getService(): string
    {
        return $this->service;
    }
}
