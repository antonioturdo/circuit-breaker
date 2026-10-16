<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Storage;

use Zeusi\CircuitBreaker\Exception\StorageException;

/**
 * Atomic primitives on integer values with expiry.
 *
 * Every circuit logic (keys, buckets, periods, state encoding, time) lives in the core: an adapter only maps
 * these primitives to its backend.
 *
 * Keys are built by the core with a partition between braces, for example "cb:v1:{payments}:h:mv3242h7:f:tmpuoa":
 * keys with the same partition are read and written together, and the keys given to one fetch() always share
 * it. Adapters of backends distributing keys may use the partition to keep those keys together: Redis Cluster
 * uses the braces natively as hash tag, a Memcached adapter can use the partition as server key. Other adapters
 * ignore it. An adapter may also prefix keys or transform them to meet backend limits, keeping the partition.
 *
 * Every method throws StorageException when the backend fails, so that the core can permit calls.
 */
interface StorageInterface
{
    /**
     * Atomically increments the value by one, creating it with value 1 and the given expiry if missing.
     *
     * @param positive-int $ttl seconds before the value expires, applied when the value is created (rounded up by the core: a TTL only
     *                          needs to be long enough)
     *
     * @return int the value after the increment
     *
     * @throws StorageException
     */
    public function increment(string $key, int $ttl): int;

    /**
     * Reads several values at once, all with the same partition.
     *
     * @param list<string> $keys
     *
     * @return array<string, int> values by key, missing or expired keys omitted
     *
     * @throws StorageException
     */
    public function fetch(array $keys): array;

    /**
     * Atomically replaces the value only if it is still the expected one.
     *
     * @param int|null     $expectedValue value read before, null if the key was missing (the value is then created only if still missing)
     * @param positive-int $ttl           seconds before the new value expires; when replacing, an adapter may keep the current expiry
     *                                    instead (APCu cannot change it atomically)
     *
     * @return bool whether the value was replaced
     *
     * @throws StorageException
     */
    public function compareAndSet(string $key, ?int $expectedValue, int $newValue, int $ttl): bool;
}
