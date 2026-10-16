<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Tests\Fixtures;

use Zeusi\CircuitBreaker\Storage\StorageInterface;

/**
 * Decorates a storage to let a test act right before the next compareAndSet().
 *
 * For example to simulate another process winning a transition, or a storage error.
 */
final class InterceptingStorage implements StorageInterface
{
    /**
     * @var (\Closure(): void)|null
     */
    private ?\Closure $beforeNextCompareAndSet = null;

    public function __construct(
        private readonly StorageInterface $storage,
    ) {}

    /**
     * @param \Closure(): void $action run once, before the next compareAndSet()
     */
    public function beforeNextCompareAndSet(\Closure $action): void
    {
        $this->beforeNextCompareAndSet = $action;
    }

    public function increment(string $key, int $ttl): int
    {
        return $this->storage->increment($key, $ttl);
    }

    public function fetch(array $keys): array
    {
        return $this->storage->fetch($keys);
    }

    public function compareAndSet(string $key, ?int $expectedValue, int $newValue, int $ttl): bool
    {
        $action = $this->beforeNextCompareAndSet;
        $this->beforeNextCompareAndSet = null;

        if ($action !== null) {
            $action();
        }

        return $this->storage->compareAndSet($key, $expectedValue, $newValue, $ttl);
    }
}
