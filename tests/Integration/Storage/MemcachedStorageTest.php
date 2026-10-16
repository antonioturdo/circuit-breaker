<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Tests\Integration\Storage;

use PHPUnit\Framework\Attributes\DataProvider;
use Zeusi\CircuitBreaker\Exception\StorageException;
use Zeusi\CircuitBreaker\Storage\MemcachedStorage;
use Zeusi\CircuitBreaker\Storage\StorageInterface;
use Zeusi\CircuitBreaker\Tests\Fixtures\StorageContractTestCase;

/**
 * Needs a Memcached server at MEMCACHED_HOST (skipped when the variable is not set).
 */
final class MemcachedStorageTest extends StorageContractTestCase
{
    protected function createStorage(): StorageInterface
    {
        $host = getenv('MEMCACHED_HOST');

        if (!\extension_loaded('memcached') || $host === false || $host === '') {
            self::backendUnavailable('Memcached is not available (the memcached extension and MEMCACHED_HOST are needed)');
        }

        $memcached = self::connect();
        $memcached->flush();

        return new MemcachedStorage($memcached, 'test:');
    }

    protected function createStorageForProcess(): StorageInterface
    {
        return new MemcachedStorage(self::connect(), 'test:');
    }

    /**
     * @return iterable<string, array{\Closure(MemcachedStorage): mixed}>
     */
    public static function operationProvider(): iterable
    {
        yield 'increment' => [static fn(MemcachedStorage $storage) => $storage->increment('cb:v1:{payments}:h:mv3242h7:calls', 60)];
        yield 'fetch' => [static fn(MemcachedStorage $storage) => $storage->fetch(['cb:v1:{payments}:state'])];
        yield 'create' => [static fn(MemcachedStorage $storage) => $storage->compareAndSet('cb:v1:{payments}:state', null, 1, 60)];
        yield 'replace' => [static fn(MemcachedStorage $storage) => $storage->compareAndSet('cb:v1:{payments}:state', 1, 2, 60)];
    }

    /**
     * @param \Closure(MemcachedStorage): mixed $operation
     */
    #[DataProvider('operationProvider')]
    public function testUnavailableServer(\Closure $operation): void
    {
        $memcached = new \Memcached();
        // nothing listens on this port
        $memcached->addServer('127.0.0.1', 1);

        $this->expectException(StorageException::class);
        $operation(new MemcachedStorage($memcached));
    }

    private static function connect(): \Memcached
    {
        $memcached = new \Memcached();
        $memcached->addServer((string) getenv('MEMCACHED_HOST'), (int) (getenv('MEMCACHED_PORT') ?: 11211));

        return $memcached;
    }
}
