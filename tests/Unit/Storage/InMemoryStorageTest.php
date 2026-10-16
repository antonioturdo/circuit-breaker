<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Tests\Unit\Storage;

use Zeusi\CircuitBreaker\Storage\InMemoryStorage;
use Zeusi\CircuitBreaker\Storage\StorageInterface;
use Zeusi\CircuitBreaker\Tests\Fixtures\FakeClock;
use Zeusi\CircuitBreaker\Tests\Fixtures\StorageContractTestCase;

final class InMemoryStorageTest extends StorageContractTestCase
{
    private FakeClock $clock;

    protected function createStorage(): StorageInterface
    {
        $this->clock = new FakeClock();

        return new InMemoryStorage($this->clock);
    }

    public function testExpiryFollowsTheClock(): void
    {
        self::assertSame(1, $this->storage->increment('cb:{a}:x', 10));

        // the TTL is applied on creation only
        $this->clock->advance(9.999);
        self::assertSame(2, $this->storage->increment('cb:{a}:x', 10));

        $this->clock->advance(0.001);
        self::assertSame([], $this->storage->fetch(['cb:{a}:x']));
        self::assertSame(1, $this->storage->increment('cb:{a}:x', 10));

        // an expired value counts as missing
        self::assertTrue($this->storage->compareAndSet('cb:{a}:state', null, 5, 10));
        $this->clock->advance(10);
        self::assertFalse($this->storage->compareAndSet('cb:{a}:state', 5, 6, 10));
        self::assertTrue($this->storage->compareAndSet('cb:{a}:state', null, 6, 10));
    }
}
