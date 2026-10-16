<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Event;

use Zeusi\CircuitBreaker\State;

/**
 * The circuit opened, from the closed state or again from the half-open state.
 */
final class CircuitOpened implements EventInterface
{
    /**
     * @param State $previousState state before opening: closed, or half-open when the service failed again
     */
    public function __construct(
        private readonly string $service,
        public readonly State $previousState,
    ) {}

    public function getService(): string
    {
        return $this->service;
    }
}
