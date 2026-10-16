<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\HalfOpen;

use Zeusi\CircuitBreaker\Exception\InvalidArgumentException;

/**
 * Lets through a growing ratio of calls, following a curve.
 *
 * The half-open state lasts as long as the ramp, never closing early.
 */
final class RampPolicy implements HalfOpenPolicyInterface
{
    private readonly float $durationMilliseconds;

    /**
     * @param float $duration seconds the ramp takes to let all calls through, at least 1
     */
    public function __construct(
        public readonly float $duration,
        public readonly RampCurveInterface $curve,
    ) {
        if ($duration < 1.0) {
            throw new InvalidArgumentException(\sprintf('The duration must be at least 1 second, %s seconds given', $duration));
        }

        $this->durationMilliseconds = $duration * 1000;
    }

    /**
     * Creates a ramp following an exponential curve.
     *
     * @param float $duration     seconds the ramp takes to let all calls through, at least 1
     * @param float $initialRatio ratio of calls let through at the start of the ramp, greater than 0 and at most 1
     */
    public static function createExponential(float $duration, float $initialRatio): self
    {
        return new self($duration, new ExponentialRampCurve($initialRatio));
    }

    /**
     * Creates a ramp following a linear curve, a power curve with exponent 1.
     *
     * @param float $duration     seconds the ramp takes to let all calls through, at least 1
     * @param float $initialRatio ratio of calls let through at the start of the ramp, from 0 to 1
     */
    public static function createLinear(float $duration, float $initialRatio): self
    {
        return new self($duration, new PowerRampCurve($initialRatio, 1.0));
    }

    /**
     * Creates a ramp following a power curve.
     *
     * @param float $duration     seconds the ramp takes to let all calls through, at least 1
     * @param float $initialRatio ratio of calls let through at the start of the ramp, from 0 to 1
     * @param float $exponent     power of the progress, greater than 0: 1 is linear, greater starts more slowly
     */
    public static function createPower(float $duration, float $initialRatio, float $exponent): self
    {
        return new self($duration, new PowerRampCurve($initialRatio, $exponent));
    }

    public function grantsPermission(PermissionRequest $request): bool
    {
        $progress = $request->elapsed / $this->durationMilliseconds;

        if ($progress >= 1.0) {
            return true;
        }

        $ratio = $this->curve->computeAdmissionRatio(max(0.0, $progress));

        return mt_rand() / mt_getrandmax() < $ratio;
    }

    public function allowsEarlyClosing(HalfOpenProgress $progress): bool
    {
        return false;
    }

    public function getMaxDuration(): float
    {
        return $this->duration;
    }
}
