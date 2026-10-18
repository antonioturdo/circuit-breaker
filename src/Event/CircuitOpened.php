<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Event;

use Zeusi\CircuitBreaker\Opening\OpeningCause;
use Zeusi\CircuitBreaker\State;

/**
 * The circuit opened, from the closed state or again from the half-open state.
 */
final class CircuitOpened implements EventInterface
{
    /**
     * @param State        $previousState state before opening: closed, or half-open when the service failed again
     * @param OpeningCause $openingCause  the rule that required the opening, with the outcomes it evaluated
     */
    public function __construct(
        private readonly string $service,
        public readonly State $previousState,
        public readonly OpeningCause $openingCause,
    ) {}

    public function getService(): string
    {
        return $this->service;
    }
}
