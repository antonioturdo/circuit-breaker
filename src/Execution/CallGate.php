<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Execution;

use Zeusi\CircuitBreaker\CircuitBreakerInterface;
use Zeusi\CircuitBreaker\Exception\CallNotPermittedException;

/**
 * Admits calls through a circuit breaker, handing out an attempt that registers the outcome of each one.
 *
 * The building block for asynchronous calls and integrations: the outcome can be registered from whatever callback
 * completes the call (a promise, a stream loop, a client decorator). Synchronous calls are simpler with CallExecutor.
 */
final class CallGate
{
    public function __construct(
        private readonly CircuitBreakerInterface $circuitBreaker,
        private readonly OutcomeClassifierInterface $outcomeClassifier,
    ) {}

    /**
     * Admits a call when the circuit permits it.
     *
     * @throws CallNotPermittedException when the circuit does not permit the call, which must then not be made
     */
    public function admit(): CallAttempt
    {
        if (!$this->circuitBreaker->acquirePermission()) {
            throw new CallNotPermittedException($this->circuitBreaker->getService());
        }

        return new CallAttempt($this->circuitBreaker, $this->outcomeClassifier);
    }
}
