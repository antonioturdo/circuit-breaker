<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Execution;

use Zeusi\CircuitBreaker\Outcome;

/**
 * Classifies the outcome of a call, for the calls made with a client or a library.
 *
 * A failure when the service did not answer or answered with an error of its own, a success when it answered, even
 * with an error of the caller, null to register nothing when the outcome says nothing about the service.
 *
 * A classifier must not throw: its exceptions are not caught, and replace the outcome of the call.
 */
interface OutcomeClassifierInterface
{
    /**
     * Classifies a call that threw: null to register nothing.
     */
    public function classifyException(\Throwable $exception): ?Outcome;

    /**
     * Classifies a call that returned, for clients returning errors instead of throwing them: null to register nothing.
     */
    public function classifyResult(mixed $result): ?Outcome;
}
