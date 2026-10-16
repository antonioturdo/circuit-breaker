<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Clock;

/**
 * Source of the current time for every time computation of the circuit, replaceable in tests.
 */
interface ClockInterface
{
    /**
     * @return int Unix timestamp in milliseconds
     */
    public function getCurrentTime(): int;
}
