<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Storage;

use Zeusi\CircuitBreaker\Exception\LogicException;
use Zeusi\CircuitBreaker\Exception\StorageException;

/**
 * Storage in APCu.
 *
 * The circuit state is shared by the processes of a single PHP master process (a PHP-FPM pool, a CLI command), so
 * every server, pool and CLI command has its own circuits.
 *
 * apcu_cas() keeps the expiry of the value it replaces: a replaced value expires one TTL after its creation, not
 * after its last replacement.
 */
final class ApcuStorage implements StorageInterface
{
    /**
     * @param string $prefix prepended to every key, to separate applications sharing the cache
     */
    public function __construct(
        private readonly string $prefix = '',
    ) {
        if (!\extension_loaded('apcu')) {
            throw new LogicException('The APCu storage requires the apcu extension');
        }
    }

    public function increment(string $key, int $ttl): int
    {
        $incrementedValue = apcu_inc($this->prefix . $key, 1, $success, $ttl);

        if (!$success || $incrementedValue === false) {
            throw new StorageException(\sprintf('Failed to increment "%s" in APCu', $key));
        }

        return $incrementedValue;
    }

    public function fetch(array $keys): array
    {
        // a disabled APCu fetches an empty array, which looks like no values
        if (!apcu_enabled()) {
            throw new StorageException('APCu is not enabled (apc.enable_cli is needed in CLI)');
        }

        $prefixed = [];

        foreach ($keys as $key) {
            $prefixed[$this->prefix . $key] = $key;
        }

        $fetchedValues = apcu_fetch(array_keys($prefixed));

        if (!\is_array($fetchedValues)) {
            throw new StorageException('Failed to fetch from APCu');
        }

        $valuesByKey = [];

        foreach ($fetchedValues as $prefixedKey => $fetchedValue) {
            $valuesByKey[$prefixed[$prefixedKey]] = (int) $fetchedValue;
        }

        return $valuesByKey;
    }

    public function compareAndSet(string $key, ?int $expectedValue, int $newValue, int $ttl): bool
    {
        // a disabled APCu returns false, which looks like a lost compare-and-set
        if (!apcu_enabled()) {
            throw new StorageException('APCu is not enabled (apc.enable_cli is needed in CLI)');
        }

        if ($expectedValue === null) {
            return apcu_add($this->prefix . $key, $newValue, $ttl) === true;
        }

        return apcu_cas($this->prefix . $key, $expectedValue, $newValue);
    }
}
