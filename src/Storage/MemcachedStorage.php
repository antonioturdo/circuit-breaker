<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Storage;

use Zeusi\CircuitBreaker\Exception\LogicException;
use Zeusi\CircuitBreaker\Exception\StorageException;

/**
 * Storage in Memcached through the memcached extension.
 *
 * The partition between braces of the keys is used as server key, so the keys of a service live on the same
 * server and fetch() reads them in one round trip. Keys longer than 250 bytes or containing spaces or control
 * characters are hashed.
 */
final class MemcachedStorage implements StorageInterface
{
    private const MAX_KEY_LENGTH = 250;

    // Memcached reads longer expirations as Unix timestamps
    private const MAX_RELATIVE_EXPIRATION = 2_592_000;

    /**
     * @param string $prefix prepended to every key, to separate applications sharing the servers
     */
    public function __construct(
        private readonly \Memcached $memcached,
        private readonly string $prefix = '',
    ) {
        if (!\extension_loaded('memcached')) {
            throw new LogicException('The Memcached storage requires the memcached extension');
        }
    }

    public function increment(string $key, int $ttl): int
    {
        [$serverKey, $storedKey] = $this->locateKey($key);

        // with the ASCII protocol a missing value is not created by increment(): add it, unless a concurrent call did
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $incrementedValue = $this->memcached->incrementByKey($serverKey, $storedKey, 1);

            if ($incrementedValue !== false) {
                return $incrementedValue;
            }

            $this->assertLastResultIs(\Memcached::RES_NOTFOUND, 'increment');

            if ($this->memcached->addByKey($serverKey, $storedKey, 1, self::convertTtlToExpiration($ttl))) {
                return 1;
            }

            $this->assertLastResultIs(\Memcached::RES_NOTSTORED, 'add');
        }

        throw new StorageException(\sprintf('Failed to increment "%s" in Memcached', $key));
    }

    public function fetch(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $storedKeys = [];
        $serverKey = '';

        foreach ($keys as $key) {
            [$serverKey, $storedKey] = $this->locateKey($key);
            $storedKeys[$storedKey] = $key;
        }

        // false on failure, or when no key is found with some library versions
        $fetchedValues = $this->memcached->getMultiByKey($serverKey, array_keys($storedKeys));

        if ($this->memcached->getResultCode() !== \Memcached::RES_SUCCESS) {
            $this->assertLastResultIs(\Memcached::RES_NOTFOUND, 'fetch');
        }

        $valuesByKey = [];

        foreach ($fetchedValues ?: [] as $storedKey => $fetchedValue) {
            $valuesByKey[$storedKeys[$storedKey]] = (int) $fetchedValue;
        }

        return $valuesByKey;
    }

    public function compareAndSet(string $key, ?int $expectedValue, int $newValue, int $ttl): bool
    {
        [$serverKey, $storedKey] = $this->locateKey($key);

        if ($expectedValue === null) {
            if ($this->memcached->addByKey($serverKey, $storedKey, $newValue, self::convertTtlToExpiration($ttl))) {
                return true;
            }

            $this->assertLastResultIs(\Memcached::RES_NOTSTORED, 'add');

            return false;
        }

        $storedEntry = $this->memcached->getByKey($serverKey, $storedKey, null, \Memcached::GET_EXTENDED);

        if (!\is_array($storedEntry)) {
            $this->assertLastResultIs(\Memcached::RES_NOTFOUND, 'get');

            return false;
        }

        if ((int) $storedEntry['value'] !== $expectedValue) {
            return false;
        }

        if ($this->memcached->casByKey($storedEntry['cas'], $serverKey, $storedKey, $newValue, self::convertTtlToExpiration($ttl))) {
            return true;
        }

        $this->assertLastResultIs(\Memcached::RES_DATA_EXISTS, 'cas');

        return false;
    }

    private static function convertTtlToExpiration(int $ttl): int
    {
        return $ttl > self::MAX_RELATIVE_EXPIRATION ? time() + $ttl : $ttl;
    }

    /**
     * @return array{string, string} the server key (the partition) and the key as stored
     */
    private function locateKey(string $key): array
    {
        $serverKey = preg_match('/\{([^}]*)\}/', $key, $matches) === 1 ? $matches[1] : $key;
        $storedKey = $this->prefix . $key;

        if (\strlen($storedKey) > self::MAX_KEY_LENGTH || preg_match('/[\x00-\x20\x7f]/', $storedKey) === 1) {
            $storedKey = 'cb:' . hash('xxh128', $storedKey);
        }

        if (\strlen($serverKey) > self::MAX_KEY_LENGTH || preg_match('/[\x00-\x20\x7f]/', $serverKey) === 1) {
            $serverKey = hash('xxh128', $serverKey);
        }

        return [$serverKey, $storedKey];
    }

    /**
     * Throws unless the last operation failed with the expected, harmless result.
     *
     * @throws StorageException
     */
    private function assertLastResultIs(int $harmless, string $operation): void
    {
        $code = $this->memcached->getResultCode();

        if ($code !== $harmless) {
            throw new StorageException(\sprintf('Failed to %s in Memcached: %s', $operation, $this->memcached->getResultMessage()));
        }
    }
}
