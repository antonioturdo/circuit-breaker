<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker;

use Zeusi\CircuitBreaker\Exception\LogicException;
use Zeusi\CircuitBreaker\Opening\OpeningCause;
use Zeusi\CircuitBreaker\Storage\CircuitState;

/**
 * A change to a target state, with its cause when the circuit opens.
 *
 * @internal
 */
final class Transition
{
    private function __construct(
        public readonly CircuitState $targetState,
        private readonly ?OpeningCause $openingCause,
    ) {}

    /**
     * @param int $since Unix timestamp in milliseconds of the transition
     */
    public static function createOpening(int $since, OpeningCause $openingCause): self
    {
        return new self(new CircuitState(State::Open, $since), $openingCause);
    }

    /**
     * @param int $since Unix timestamp in milliseconds of the transition
     */
    public static function createHalfOpening(int $since): self
    {
        return new self(new CircuitState(State::HalfOpen, $since), null);
    }

    /**
     * @param int $since Unix timestamp in milliseconds of the transition
     */
    public static function createClosing(int $since): self
    {
        return new self(new CircuitState(State::Closed, $since), null);
    }

    /**
     * Gets the cause of an opening transition.
     *
     * @throws LogicException if the transition is not an opening: only openings are created with a cause
     */
    public function getOpeningCause(): OpeningCause
    {
        return $this->openingCause ?? throw new LogicException('Only an opening transition has a cause');
    }
}
