<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Execution;

use Zeusi\CircuitBreaker\CircuitBreakerInterface;
use Zeusi\CircuitBreaker\Exception\CallNotPermittedException;
use Zeusi\CircuitBreaker\Exception\InvalidArgumentException;
use Zeusi\CircuitBreaker\Outcome;

/**
 * A call admitted through a circuit breaker, registering its outcome once.
 *
 * The building block for asynchronous calls and integrations: the outcome can be registered from whatever callback
 * completes the call (a promise, a stream loop, a client decorator), known directly or given by an
 * OutcomeClassifierInterface. The duration comes from whoever made the call: a client usually knows it (the total time
 * of an HTTP response), without the waits on the caller side such as a queue of the client. Only the first registration
 * counts: asynchronous callbacks may report the same call more than once. Synchronous calls are simpler with
 * CallExecutor.
 */
final class CallAttempt
{
    private bool $isRegistered = false;

    private function __construct(
        private readonly CircuitBreakerInterface $circuitBreaker,
    ) {}

    /**
     * Admits a call when the circuit permits it.
     *
     * @throws CallNotPermittedException when the circuit does not permit the call, which must then not be made
     */
    public static function admit(CircuitBreakerInterface $circuitBreaker): self
    {
        if (!$circuitBreaker->acquirePermission()) {
            throw new CallNotPermittedException($circuitBreaker->getService());
        }

        return new self($circuitBreaker);
    }

    /**
     * Registers the outcome of the call, unless one was already registered.
     *
     * @param Outcome|null $outcome  null to register nothing, when the call says nothing about the service
     * @param float|null   $duration seconds the call took, null when unknown: an unknown duration is neither slow nor fast
     *
     * @throws InvalidArgumentException if the duration is negative or not finite
     */
    public function register(?Outcome $outcome, ?float $duration): void
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
