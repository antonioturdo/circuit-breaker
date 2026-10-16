<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Storage;

use Zeusi\CircuitBreaker\Exception\StorageException;
use Zeusi\CircuitBreaker\State;

/**
 * The state of a circuit with the time it started.
 *
 * It is encoded in a single integer, so that it can be replaced with compareAndSet(). Layout (44 bits): Unix time in
 * milliseconds (42 bits), state (2 bits).
 *
 * @internal
 */
final class CircuitState
{
    private const STATE_BITS = 2;
    private const TIME_BITS = 42;

    /**
     * @param int      $since       Unix timestamp in milliseconds of the transition to this state, 0 for the initial state
     * @param int|null $storedValue the stored value this state was decoded from, expected by the next compare-and-set:
     *                              null when nothing was stored, or for a state not stored yet
     */
    public function __construct(
        public readonly State $state,
        public readonly int $since,
        public readonly ?int $storedValue = null,
    ) {}

    /**
     * @param int|null $storedValue null when nothing is stored: the initial closed state
     */
    public static function decode(?int $storedValue): self
    {
        if ($storedValue === null) {
            return new self(State::Closed, 0);
        }

        return new self(
            self::decodeState($storedValue & ((1 << self::STATE_BITS) - 1)),
            ($storedValue >> self::STATE_BITS) & ((1 << self::TIME_BITS) - 1),
            $storedValue,
        );
    }

    /**
     * Decodes the state whose code matches, an unknown code being the initial closed state.
     */
    private static function decodeState(int $stateCode): State
    {
        foreach (State::cases() as $state) {
            if (self::getStateCode($state) === $stateCode) {
                return $state;
            }
        }

        return State::Closed;
    }

    /**
     * @throws StorageException if the time does not fit its bits
     */
    public function encode(): int
    {
        if ($this->since < 0 || $this->since >= 1 << self::TIME_BITS) {
            throw new StorageException(\sprintf('The time %d cannot be encoded in the circuit state', $this->since));
        }

        return ($this->since << self::STATE_BITS) | self::getStateCode($this->state);
    }

    /**
     * Gets the code of a state in the stored value.
     */
    private static function getStateCode(State $state): int
    {
        return match ($state) {
            State::Closed => 0,
            State::Open => 1,
            State::HalfOpen => 2,
        };
    }
}
