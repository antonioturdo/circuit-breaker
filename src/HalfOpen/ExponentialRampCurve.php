<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\HalfOpen;

use Zeusi\CircuitBreaker\Exception\InvalidArgumentException;

/**
 * Starts from an initial ratio and doubles it at regular intervals, reaching 1 at the end of the ramp.
 */
final class ExponentialRampCurve implements RampCurveInterface
{
    /**
     * @param float $initialRatio ratio of calls let through at the start of the ramp, greater than 0 and at most 1
     */
    public function __construct(
        public readonly float $initialRatio,
    ) {
        if ($initialRatio <= 0.0 || $initialRatio > 1.0) {
            throw new InvalidArgumentException(\sprintf('The initial ratio must be greater than 0 and at most 1, %s given', $initialRatio));
        }
    }

    public function computeAdmissionRatio(float $progress): float
    {
        // initialRatio * (1 / initialRatio) ** progress
        return $this->initialRatio ** (1.0 - $progress);
    }
}
