<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Execution;

use Zeusi\CircuitBreaker\CircuitBreakerInterface;
use Zeusi\CircuitBreaker\Exception\InvalidArgumentException;
use Zeusi\CircuitBreaker\Outcome;

/**
 * A call admitted by a CallGate, registering its outcome once.
 *
 * The duration comes from whoever made the call: a client usually knows it (the total time of an HTTP response),
 * without the waits on the caller side such as a queue of the client. Only the first registration counts: asynchronous
 * callbacks may report the same call more than once.
 */
final class CallAttempt
{
    private bool $isRegistered = false;

    /**
     * @internal created by CallGate::admit()
     */
    public function __construct(
        private readonly CircuitBreakerInterface $circuitBreaker,
        private readonly OutcomeClassifierInterface $outcomeClassifier,
    ) {}

    /**
     * Registers the outcome of a call that returned, as classified.
     *
     * @param float|null $duration seconds the call took, null when unknown: an unknown duration is neither slow nor fast
     *
     * @throws InvalidArgumentException if the duration is negative or not finite
     */
    public function registerResult(mixed $result, ?float $duration): void
    {
        $this->registerOutcome($this->outcomeClassifier->classifyResult($result), $duration);
    }

    /**
     * Registers the outcome of a call that threw, as classified.
     *
     * @param float|null $duration seconds the call took, null when unknown: an unknown duration is neither slow nor fast
     *
     * @throws InvalidArgumentException if the duration is negative or not finite
     */
    public function registerException(\Throwable $exception, ?float $duration): void
    {
        $this->registerOutcome($this->outcomeClassifier->classifyException($exception), $duration);
    }

    /**
     * @param Outcome|null $outcome null to register nothing
     */
    private function registerOutcome(?Outcome $outcome, ?float $duration): void
    {
        if ($this->isRegistered) {
            return;
        }

        $this->isRegistered = true;

        match ($outcome) {
            Outcome::Failure => $this->circuitBreaker->registerFailure($duration),
            Outcome::Success => $this->circuitBreaker->registerSuccess($duration),
            null => null,
        };
    }
}
