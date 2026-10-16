<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Tests\Unit\HalfOpen;

use PHPUnit\Framework\TestCase;
use Zeusi\CircuitBreaker\HalfOpen\PermissionRequest;
use Zeusi\CircuitBreaker\Storage\InMemoryStorage;

final class PermissionRequestTest extends TestCase
{
    public function testCallIsCountedOnceWhenItsNumberIsRead(): void
    {
        $storage = new InMemoryStorage();
        $storage->increment('calls', 60);
        $request = new PermissionRequest(500, static fn(): int => $storage->increment('calls', 60));

        self::assertSame(['calls' => 1], $storage->fetch(['calls']));
        self::assertSame(2, $request->getCallNumber());
        self::assertSame(2, $request->getCallNumber());
        self::assertSame(['calls' => 2], $storage->fetch(['calls']));
    }
}
