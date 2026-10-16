<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Event;

/**
 * The open duration elapsed and the circuit became half-open.
 */
final class CircuitHalfOpened implements EventInterface
{
    public function __construct(
        private readonly string $service,
    ) {}

    public function getService(): string
    {
        return $this->service;
    }
}
