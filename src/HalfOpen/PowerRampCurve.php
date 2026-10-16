<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\HalfOpen;

use Zeusi\CircuitBreaker\Exception\InvalidArgumentException;

/**
 * Starts from an initial ratio and grows with a power of the progress, reaching 1 at the end of the ramp.
 *
 * An exponent of 1 grows at a constant pace. A greater exponent spends more time on low ratios at first: with 2 and an
 * initial ratio of 0, a quarter of the calls are let through half way. A lower one grows faster at first.
 */
final class PowerRampCurve implements RampCurveInterface
{
    /**
     * @param float $initialRatio ratio of calls let through at the start of the ramp, from 0 to 1
     * @param float $exponent     power of the progress, greater than 0
     */
    public function __construct(
        public readonly float $initialRatio,
        public readonly float $exponent,
    ) {
        if ($initialRatio < 0.0 || $initialRatio > 1.0) {
            throw new InvalidArgumentException(\sprintf('The initial ratio must be between 0 and 1, %s given', $initialRatio));
        }

        if ($exponent <= 0.0) {
            throw new InvalidArgumentException(\sprintf('The exponent must be greater than 0, %s given', $exponent));
        }
    }

    public function computeAdmissionRatio(float $progress): float
    {
        return $this->initialRatio + (1.0 - $this->initialRatio) * $progress ** $this->exponent;
    }
}
