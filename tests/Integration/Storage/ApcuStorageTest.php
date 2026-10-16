<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Tests\Integration\Storage;

use Zeusi\CircuitBreaker\Storage\ApcuStorage;
use Zeusi\CircuitBreaker\Storage\StorageInterface;
use Zeusi\CircuitBreaker\Tests\Fixtures\StorageContractTestCase;

final class ApcuStorageTest extends StorageContractTestCase
{
    protected function createStorage(): StorageInterface
    {
        if (!\extension_loaded('apcu') || !apcu_enabled()) {
            self::backendUnavailable('APCu is not available (the extension and apc.enable_cli=1 are needed)');
        }

        apcu_clear_cache();

        return new ApcuStorage('test:');
    }

    protected function createStorageForProcess(): StorageInterface
    {
        // forked processes share the APCu memory
        return new ApcuStorage('test:');
    }
}
