<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Clock;

final class SystemClock implements ClockInterface
{
    public function getCurrentTime(): int
    {
        return (int) (microtime(true) * 1000);
    }
}
