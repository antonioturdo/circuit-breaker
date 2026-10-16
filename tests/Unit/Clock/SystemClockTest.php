<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Tests\Unit\Clock;

use PHPUnit\Framework\TestCase;
use Zeusi\CircuitBreaker\Clock\SystemClock;

final class SystemClockTest extends TestCase
{
    public function testNowInMilliseconds(): void
    {
        $before = (int) (microtime(true) * 1000);
        $now = (new SystemClock())->getCurrentTime();
        $after = (int) (microtime(true) * 1000);

        self::assertGreaterThanOrEqual($before, $now);
        self::assertLessThanOrEqual($after, $now);
    }
}
