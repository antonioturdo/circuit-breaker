<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Tests\Integration\Storage;

use PHPUnit\Framework\Attributes\DataProvider;
use Zeusi\CircuitBreaker\Exception\InvalidArgumentException;
use Zeusi\CircuitBreaker\Exception\StorageException;
use Zeusi\CircuitBreaker\Storage\RedisStorage;
use Zeusi\CircuitBreaker\Storage\StorageInterface;
use Zeusi\CircuitBreaker\Tests\Fixtures\StorageContractTestCase;

/**
 * Needs a Redis server at REDIS_HOST (skipped when the variable is not set).
 */
final class RedisStorageTest extends StorageContractTestCase
{
    protected function createStorage(): StorageInterface
    {
        $host = getenv('REDIS_HOST');

        if (!\extension_loaded('redis') || $host === false || $host === '') {
            self::backendUnavailable('Redis is not available (the redis extension and REDIS_HOST are needed)');
        }

        $redis = self::connect();
        $redis->flushDB();

        return new RedisStorage($redis, 'test:');
    }

    protected function createStorageForProcess(): StorageInterface
    {
        return new RedisStorage(self::connect(), 'test:');
    }

    /**
     * @return iterable<string, array{\Closure(RedisStorage): mixed}>
     */
    public static function operationProvider(): iterable
    {
        yield 'increment' => [static fn(RedisStorage $storage) => $storage->increment('cb:v1:{payments}:h:mv3242h7:calls', 60)];
        yield 'fetch' => [static fn(RedisStorage $storage) => $storage->fetch(['cb:v1:{payments}:state'])];
        yield 'create' => [static fn(RedisStorage $storage) => $storage->compareAndSet('cb:v1:{payments}:state', null, 1, 60)];
        yield 'replace' => [static fn(RedisStorage $storage) => $storage->compareAndSet('cb:v1:{payments}:state', 1, 2, 60)];
    }

    /**
     * @param \Closure(RedisStorage): mixed $operation
     */
    #[DataProvider('operationProvider')]
    public function testUnavailableServer(\Closure $operation): void
    {
        $this->expectException(StorageException::class);

        // phpredis connects lazily, by default to 127.0.0.1:6379 where a server may listen: nothing listens on port 1
        $operation(new RedisStorage(new \Redis(['host' => '127.0.0.1', 'port' => 1])));
    }

    public function testErrorReply(): void
    {
        $redis = self::connect();
        $redis->rPush('test:cb:v1:{payments}:h:mv3242h7:calls', 'not a counter');

        $this->expectException(StorageException::class);
        $this->storage->increment('cb:v1:{payments}:h:mv3242h7:calls', 60);
    }

    /**
     * @return iterable<string, array{int, mixed, string}>
     */
    public static function invalidConfigurationProvider(): iterable
    {
        yield 'serializer' => [\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP, ''];
        yield 'braces in the prefix' => [\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_NONE, '{shop}:'];
        yield 'braces in the client prefix' => [\Redis::OPT_PREFIX, '{shop}:', ''];
    }

    #[DataProvider('invalidConfigurationProvider')]
    public function testInvalidConfiguration(int $option, mixed $optionValue, string $prefix): void
    {
        $redis = self::connect();
        $redis->setOption($option, $optionValue);

        $this->expectException(InvalidArgumentException::class);
        new RedisStorage($redis, $prefix);
    }

    private static function connect(): \Redis
    {
        $redis = new \Redis();
        $redis->connect((string) getenv('REDIS_HOST'), (int) (getenv('REDIS_PORT') ?: 6379));

        return $redis;
    }
}
