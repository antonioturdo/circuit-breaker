<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\HalfOpen;

/**
 * Shape of a ramp: the ratio of calls let through as the ramp progresses.
 *
 * The duration belongs to the ramp policy: the curve only maps the progress to a ratio, so the same
 * curve fits any duration.
 */
interface RampCurveInterface
{
    /**
     * @param float $progress elapsed fraction of the ramp duration, from 0 (start) to 1 (end)
     *
     * @return float ratio of calls let through, from 0 to 1, expected not to decrease as progress grows
     */
    public function computeAdmissionRatio(float $progress): float;
}
