<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\HalfOpen;

use Zeusi\CircuitBreaker\Exception\InvalidArgumentException;

/**
 * Lets through the first calls of the half-open state.
 *
 * Closing early is allowed once all of them have reported back. Lost outcomes never keep the circuit half-open: the
 * half-open state ends at the maximum duration anyway.
 *
 * With a single permitted call this is the classic half-open trial.
 */
final class PermittedCallsPolicy implements HalfOpenPolicyInterface
{
    /**
     * @param float $maxDuration    seconds the half-open state lasts at most, even without all outcomes, at least 1
     * @param int   $permittedCalls calls let through, at least 1
     */
    public function __construct(
        public readonly float $maxDuration,
        public readonly int $permittedCalls,
    ) {
        if ($maxDuration < 1.0) {
            throw new InvalidArgumentException(\sprintf('The maximum duration must be at least 1 second, %s seconds given', $maxDuration));
        }

        if ($permittedCalls < 1) {
            throw new InvalidArgumentException(\sprintf('The permitted calls must be at least 1, %d given', $permittedCalls));
        }
    }

    /**
     * Creates the classic half-open trial: a single call is let through.
     *
     * @param float $maxDuration seconds the half-open state lasts at most, even without the outcome, at least 1
     */
    public static function createSingleTrial(float $maxDuration): self
    {
        return new self($maxDuration, 1);
    }

    public function grantsPermission(PermissionRequest $request): bool
    {
        return $request->getCallNumber() <= $this->permittedCalls;
    }

    public function allowsEarlyClosing(HalfOpenProgress $progress): bool
    {
        return $progress->successes + $progress->failures >= $this->permittedCalls;
    }

    public function getMaxDuration(): float
    {
        return $this->maxDuration;
    }
}
