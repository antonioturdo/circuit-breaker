<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Execution;

use Zeusi\CircuitBreaker\CircuitBreakerInterface;
use Zeusi\CircuitBreaker\Exception\CallNotPermittedException;

/**
 * Runs calls through a circuit breaker, registering their outcomes and durations.
 *
 * Running the whole call, it measures its duration. The outcome is registered when the call returns or throws, so the
 * call must complete within it: a synchronous call, or an asynchronous one awaited inside a fiber (Amp, Revolt). For a
 * call returning a promise or a lazy response, use CallGate and register the outcome when the call completes.
 */
final class CallExecutor
{
    private readonly CallGate $callGate;

    public function __construct(CircuitBreakerInterface $circuitBreaker, OutcomeClassifierInterface $outcomeClassifier)
    {
        $this->callGate = new CallGate($circuitBreaker, $outcomeClassifier);
    }

    /**
     * Runs the call when the circuit permits it.
     *
     * @template T
     *
     * @param callable(): T $call
     *
     * @return T
     *
     * @throws CallNotPermittedException when the circuit does not permit the call, which then does not run
     */
    public function execute(callable $call): mixed
    {
        $attempt = $this->callGate->admit();
        $startTime = hrtime(true);

        try {
            $result = $call();
        } catch (\Throwable $exception) {
            $attempt->registerException($exception, self::getSecondsSince($startTime));

            throw $exception;
        }

        // a result classified as a failure is still returned: the caller handles it as before
        $attempt->registerResult($result, self::getSecondsSince($startTime));

        return $result;
    }

    /**
     * Gets the seconds elapsed since a time of the monotonic clock, unaffected by changes of the system time.
     *
     * @param int $startTime nanoseconds of hrtime()
     */
    private static function getSecondsSince(int $startTime): float
    {
        return (hrtime(true) - $startTime) / 1e9;
    }
}
