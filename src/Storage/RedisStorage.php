<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Storage;

use Zeusi\CircuitBreaker\Exception\InvalidArgumentException;
use Zeusi\CircuitBreaker\Exception\LogicException;
use Zeusi\CircuitBreaker\Exception\StorageException;

/**
 * Storage in Redis through the phpredis extension, standalone or cluster.
 *
 * The partition between braces of the keys works as Redis Cluster hash tag, so the keys of a service share a slot
 * and fetch() is a single MGET. Every operation uses a single key, Lua scripts included.
 */
final class RedisStorage implements StorageInterface
{
    // creates the value with its expiry, atomically, on every Redis version
    private const INCREMENT_SCRIPT = <<<'LUA'
        local value = redis.call('INCR', KEYS[1])
        if value == 1 then
            redis.call('EXPIRE', KEYS[1], ARGV[1])
        end
        return value
        LUA;

    private const COMPARE_AND_SET_SCRIPT = <<<'LUA'
        if redis.call('GET', KEYS[1]) == ARGV[1] then
            redis.call('SET', KEYS[1], ARGV[2], 'EX', ARGV[3])
            return 1
        end
        return 0
        LUA;

    /**
     * @param \Redis|\RedisCluster $redis  a client without serializer nor compression: values are raw integers
     * @param string               $prefix prepended to every key, to separate applications sharing the server; without
     *                                     braces, which would replace the hash tag of every service
     */
    public function __construct(
        private readonly \Redis|\RedisCluster $redis,
        private readonly string $prefix = '',
    ) {
        if (!\extension_loaded('redis')) {
            throw new LogicException('The Redis storage requires the redis extension (phpredis)');
        }

        if ($redis->getOption(\Redis::OPT_SERIALIZER) !== \Redis::SERIALIZER_NONE) {
            throw new InvalidArgumentException('The Redis client must not use a serializer: values are raw integers');
        }

        if (\defined('Redis::OPT_COMPRESSION') && $redis->getOption(\Redis::OPT_COMPRESSION) !== \Redis::COMPRESSION_NONE) {
            throw new InvalidArgumentException('The Redis client must not use compression: values are raw integers');
        }

        // Redis Cluster takes the first braces as hash tag: braces in a prefix would put every service in one slot
        if (strpbrk($prefix . $redis->getOption(\Redis::OPT_PREFIX), '{}') !== false) {
            throw new InvalidArgumentException('The key prefixes must contain no braces: they would replace the hash tag of the keys');
        }
    }

    public function increment(string $key, int $ttl): int
    {
        return (int) $this->runScript(self::INCREMENT_SCRIPT, [$this->prefix . $key, $ttl]);
    }

    public function fetch(array $keys): array
    {
        $fetchedValues = $this->callRedis('fetch from', fn(): mixed => $this->redis->mget(array_map(fn(string $key): string => $this->prefix . $key, $keys)));

        if (!\is_array($fetchedValues)) {
            throw new StorageException('Failed to fetch from Redis');
        }

        $valuesByKey = [];

        foreach ($keys as $index => $key) {
            if (isset($fetchedValues[$index]) && $fetchedValues[$index] !== false) {
                $valuesByKey[$key] = (int) $fetchedValues[$index];
            }
        }

        return $valuesByKey;
    }

    public function compareAndSet(string $key, ?int $expectedValue, int $newValue, int $ttl): bool
    {
        if ($expectedValue === null) {
            return $this->callRedis('set in', fn(): mixed => $this->redis->set($this->prefix . $key, (string) $newValue, ['nx', 'ex' => $ttl])) === true;
        }

        return $this->runScript(self::COMPARE_AND_SET_SCRIPT, [$this->prefix . $key, (string) $expectedValue, (string) $newValue, $ttl]) === 1;
    }

    /**
     * @param list<int|string> $arguments the key first
     *
     * @throws StorageException
     */
    private function runScript(string $script, array $arguments): mixed
    {
        $scriptResult = $this->callRedis('run a script in', fn(): mixed => $this->redis->eval($script, $arguments, 1));

        if ($scriptResult === false) {
            throw new StorageException('Failed to run a script in Redis: ' . ($this->redis->getLastError() ?? 'unknown error'));
        }

        return $scriptResult;
    }

    /**
     * @param \Closure(): mixed $operation
     *
     * @throws StorageException
     */
    private function callRedis(string $description, \Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (\RedisException|\RedisClusterException $exception) {
            throw new StorageException(\sprintf('Failed to %s Redis: %s', $description, $exception->getMessage()), 0, $exception);
        }
    }
}
