<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Tests\Fixtures;

use PHPUnit\Framework\TestCase;
use Zeusi\CircuitBreaker\CircuitBreaker;
use Zeusi\CircuitBreaker\Configuration;
use Zeusi\CircuitBreaker\Event\CircuitOpened;
use Zeusi\CircuitBreaker\HalfOpen\RampPolicy;
use Zeusi\CircuitBreaker\State;
use Zeusi\CircuitBreaker\Storage\StorageInterface;

/**
 * The contract of StorageInterface, run against every adapter.
 */
abstract class StorageContractTestCase extends TestCase
{
    private const CONCURRENT_PROCESSES = 8;

    protected StorageInterface $storage;

    abstract protected function createStorage(): StorageInterface;

    /**
     * Creates a storage for a forked process.
     *
     * The storage works on the same backend without clearing it, with a connection of its own. Storages not shared
     * between processes skip the concurrency tests.
     */
    protected function createStorageForProcess(): StorageInterface
    {
        self::markTestSkipped('The storage is not shared between processes');
    }

    /**
     * Skips the test because the backend is not available.
     *
     * When REQUIRE_STORAGES is set (in CI and Docker) the test fails instead, so that a missing extension or server
     * never goes unnoticed.
     */
    protected static function backendUnavailable(string $message): never
    {
        if (getenv('REQUIRE_STORAGES') !== false && getenv('REQUIRE_STORAGES') !== '') {
            self::fail($message);
        }

        self::markTestSkipped($message);
    }

    protected function setUp(): void
    {
        $this->storage = $this->createStorage();
    }

    public function testIncrement(): void
    {
        self::assertSame(1, $this->storage->increment($this->key('s:1'), 60));
        self::assertSame(2, $this->storage->increment($this->key('s:1'), 60));
        self::assertSame(1, $this->storage->increment($this->key('s:2'), 60));

        self::assertFetched([$this->key('s:1') => 2, $this->key('s:2') => 1], $this->storage->fetch([$this->key('s:1'), $this->key('s:2')]));
    }

    public function testFetchOmitsMissingKeys(): void
    {
        $this->storage->increment($this->key('s:1'), 60);
        $this->storage->increment($this->key('s:3'), 60);

        self::assertSame(
            [$this->key('s:1') => 1, $this->key('s:3') => 1],
            $this->storage->fetch([$this->key('s:1'), $this->key('s:2'), $this->key('s:3')]),
        );
        self::assertFetched([], $this->storage->fetch([$this->key('s:4')]));
    }

    public function testCompareAndSet(): void
    {
        // values as large as an encoded circuit state
        $first = (1 << 62) + 5;
        $second = (1 << 62) + 6;

        // replacement of a missing value
        self::assertFalse($this->storage->compareAndSet($this->key('state'), $first, $second, 60));

        // creation only if missing
        self::assertTrue($this->storage->compareAndSet($this->key('state'), null, $first, 60));
        self::assertFalse($this->storage->compareAndSet($this->key('state'), null, $second, 60));

        // replacement only if unchanged
        self::assertFalse($this->storage->compareAndSet($this->key('state'), $first - 1, $second, 60));
        self::assertTrue($this->storage->compareAndSet($this->key('state'), $first, $second, 60));
        self::assertFalse($this->storage->compareAndSet($this->key('state'), $first, $second + 1, 60));

        self::assertFetched([$this->key('state') => $second], $this->storage->fetch([$this->key('state')]));
    }

    public function testCompareAndSetOnIncrementedValue(): void
    {
        $this->storage->increment($this->key('calls'), 60);

        self::assertTrue($this->storage->compareAndSet($this->key('calls'), 1, 7, 60));
        self::assertSame(8, $this->storage->increment($this->key('calls'), 60));
    }

    public function testLongTtl(): void
    {
        // longer than 30 days, which Memcached would read as a Unix timestamp
        $ttl = 31 * 86_400;

        self::assertTrue($this->storage->compareAndSet($this->key('state'), null, 3, $ttl));
        self::assertTrue($this->storage->compareAndSet($this->key('state'), 3, 4, $ttl));
        self::assertSame(1, $this->storage->increment($this->key('calls'), $ttl));

        self::assertFetched([$this->key('state') => 4, $this->key('calls') => 1], $this->storage->fetch([$this->key('state'), $this->key('calls')]));
    }

    public function testUnusualKeys(): void
    {
        // service names may contain spaces and be long, and keys of different partitions are independent
        $long = $this->key('s:1', 'a service name with spaces ' . str_repeat('x', 300));
        $other = $this->key('s:1', 'other');

        self::assertSame(1, $this->storage->increment($long, 60));
        self::assertSame(1, $this->storage->increment($other, 60));
        self::assertTrue($this->storage->compareAndSet($this->key('state', 'a service name with spaces ' . str_repeat('x', 300)), null, 3, 60));

        self::assertFetched([$long => 1], $this->storage->fetch([$long]));
        self::assertSame(
            [$this->key('state', 'a service name with spaces ' . str_repeat('x', 300)) => 3],
            $this->storage->fetch([$this->key('state', 'a service name with spaces ' . str_repeat('x', 300))]),
        );
    }

    public function testCircuitBreakerOpens(): void
    {
        // end to end: real keys, state encoding and transitions on the backend
        $configuration = new Configuration(timeWindow: 60, failureRateThreshold: 50, minimumRequests: 10, openDuration: 10, halfOpenPolicy: RampPolicy::createExponential(60, 0.01));
        $breaker = new CircuitBreaker('payments', $configuration, $this->storage);
        $other = new CircuitBreaker('other service', $configuration, $this->storage);

        for ($i = 0; $i < 10; $i++) {
            self::assertTrue($breaker->acquirePermission());
            $breaker->registerFailure();
            $other->registerSuccess();
        }

        self::assertSame(State::Open, $breaker->getState());
        self::assertFalse($breaker->acquirePermission());
        self::assertSame(State::Closed, $other->getState());
        self::assertTrue($other->acquirePermission());
    }

    public function testConcurrentIncrements(): void
    {
        $this->runConcurrently(function (StorageInterface $storage): int {
            for ($i = 0; $i < 250; $i++) {
                $storage->increment($this->key('s:1'), 60);
            }

            return 0;
        });

        self::assertFetched([$this->key('s:1') => self::CONCURRENT_PROCESSES * 250], $this->storage->fetch([$this->key('s:1')]));
    }

    public function testConcurrentCompareAndSet(): void
    {
        $exitCodes = $this->runConcurrently(function (StorageInterface $storage): int {
            // increments through read and replace: a lost update would be a missing increment
            for ($done = 0; $done < 50;) {
                $current = $storage->fetch([$this->key('counter')])[$this->key('counter')] ?? null;

                if ($storage->compareAndSet($this->key('counter'), $current, ($current ?? 0) + 1, 60)) {
                    $done++;
                }
            }

            // a single process creates a missing value
            return $storage->compareAndSet($this->key('state'), null, getmypid() ?: 1, 60) ? 1 : 0;
        });

        self::assertSame(1, array_sum($exitCodes));
        self::assertFetched([$this->key('counter') => self::CONCURRENT_PROCESSES * 50], $this->storage->fetch([$this->key('counter')]));
    }

    public function testConcurrentFailuresOpenTheCircuitOnce(): void
    {
        $configuration = new Configuration(timeWindow: 60, failureRateThreshold: 50, minimumRequests: 10, openDuration: 10, halfOpenPolicy: RampPolicy::createExponential(60, 0.01));
        $breaker = new CircuitBreaker('payments', $configuration, $this->storage);

        for ($i = 0; $i < 9; $i++) {
            $breaker->registerFailure();
        }

        // every process sees enough failures to open the circuit: a single one wins the transition
        $exitCodes = $this->runConcurrently(static function (StorageInterface $storage) use ($configuration): int {
            $listener = new RecordingEventListener();
            (new CircuitBreaker('payments', $configuration, $storage, $listener))->registerFailure();

            return \count(array_filter($listener->flush(), static fn(object $event): bool => $event instanceof CircuitOpened));
        });

        self::assertSame(1, array_sum($exitCodes));
        self::assertSame(State::Open, (new CircuitBreaker('payments', $configuration, $this->storage))->getState());
    }

    /**
     * Runs the work in concurrent processes, started together, and returns their exit codes.
     *
     * @param \Closure(StorageInterface): int $work returns the exit code of the process
     *
     * @return list<int>
     */
    private function runConcurrently(\Closure $work): array
    {
        if (!\function_exists('pcntl_fork')) {
            static::backendUnavailable('The pcntl extension is needed for concurrency tests');
        }

        // skips the test for storages not shared between processes
        $this->createStorageForProcess();

        $start = microtime(true) + 0.2;
        $pids = [];

        for ($process = 0; $process < self::CONCURRENT_PROCESSES; $process++) {
            $pid = pcntl_fork();

            if ($pid === 0) {
                try {
                    $storage = $this->createStorageForProcess();
                    time_sleep_until($start);
                    $exitCode = $work($storage);
                } catch (\Throwable) {
                    $exitCode = 255;
                }

                exit($exitCode);
            }

            $pids[] = $pid;
        }

        $exitCodes = [];

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $exitCode = pcntl_wexitstatus($status);
            $exitCodes[] = $exitCode === false ? 255 : $exitCode;
        }

        // the processes inherited the connection of this one and closed it when exiting
        $this->storage = $this->createStorageForProcess();

        self::assertNotContains(255, $exitCodes, 'A process failed');

        return $exitCodes;
    }

    /**
     * fetch() promises no order.
     *
     * @param array<string, int> $expected
     * @param array<string, int> $actual
     */
    protected static function assertFetched(array $expected, array $actual): void
    {
        ksort($expected);
        ksort($actual);

        self::assertSame($expected, $actual);
    }

    protected function key(string $name, string $partition = 'payments'): string
    {
        return \sprintf('cb:v1:{%s}:h:mv3242h7:%s', $partition, $name);
    }
}
