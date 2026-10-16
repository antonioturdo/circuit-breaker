<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Tests\Fixtures;

use Zeusi\CircuitBreaker\Clock\ClockInterface;

final class FakeClock implements ClockInterface
{
    public function __construct(
        private int $now = 1_791_676_800_000,
    ) {}

    public function getCurrentTime(): int
    {
        return $this->now;
    }

    public function advance(float $seconds): void
    {
        $this->now += (int) round($seconds * 1000);
    }
}
