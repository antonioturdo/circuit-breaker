<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Storage;

use Zeusi\CircuitBreaker\Clock\ClockInterface;
use Zeusi\CircuitBreaker\Clock\SystemClock;

/**
 * Storage in the memory of the current process: the circuit state is not shared with other processes.
 *
 * Useful in tests and in long-running processes. Expiries follow the given clock, so that they also follow a fake
 * clock in tests.
 */
final class InMemoryStorage implements StorageInterface
{
    private readonly ClockInterface $clock;

    /**
     * @var array<string, array{value: int, expiresAt: int}>
     */
    private array $entries = [];

    public function __construct(?ClockInterface $clock = null)
    {
        $this->clock = $clock ?? new SystemClock();
    }

    public function increment(string $key, int $ttl): int
    {
        $liveValue = $this->getLiveValue($key);

        if ($liveValue === null) {
            $this->entries[$key] = ['value' => 1, 'expiresAt' => $this->computeExpiry($ttl)];

            return 1;
        }

        return ++$this->entries[$key]['value'];
    }

    public function fetch(array $keys): array
    {
        $liveValues = [];

        foreach ($keys as $key) {
            $liveValue = $this->getLiveValue($key);

            if ($liveValue !== null) {
                $liveValues[$key] = $liveValue;
            }
        }

        return $liveValues;
    }

    public function compareAndSet(string $key, ?int $expectedValue, int $newValue, int $ttl): bool
    {
        if ($this->getLiveValue($key) !== $expectedValue) {
            return false;
        }

        $this->entries[$key] = ['value' => $newValue, 'expiresAt' => $this->computeExpiry($ttl)];

        return true;
    }

    private function getLiveValue(string $key): ?int
    {
        if (!isset($this->entries[$key])) {
            return null;
        }

        if ($this->entries[$key]['expiresAt'] <= $this->clock->getCurrentTime()) {
            unset($this->entries[$key]);

            return null;
        }

        return $this->entries[$key]['value'];
    }

    private function computeExpiry(int $ttl): int
    {
        return $this->clock->getCurrentTime() + $ttl * 1000;
    }
}
