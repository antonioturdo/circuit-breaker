<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Execution;

use Zeusi\CircuitBreaker\Outcome;

/**
 * Classifies outcomes with callbacks, for calls not worth a classifier of their own.
 */
final class CallbackOutcomeClassifier implements OutcomeClassifierInterface
{
    /**
     * @param \Closure(\Throwable): ?Outcome      $classifyException the outcome of a call that threw
     * @param (\Closure(mixed): ?Outcome)|null $classifyResult    the outcome of a call that returned; null when every
     *                                                            returned value is a success
     */
    public function __construct(
        private readonly \Closure $classifyException,
        private readonly ?\Closure $classifyResult = null,
    ) {}

    public function classifyException(\Throwable $exception): ?Outcome
    {
        return ($this->classifyException)($exception);
    }

    public function classifyResult(mixed $result): ?Outcome
    {
        return $this->classifyResult === null ? Outcome::Success : ($this->classifyResult)($result);
    }
}
