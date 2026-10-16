<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Event;

use Zeusi\CircuitBreaker\Exception\StorageException;

/**
 * The storage failed and the call was let through.
 *
 * Calls are permitted while the storage fails, so the circuit does not protect the service until it recovers.
 */
final class StorageFailed implements EventInterface
{
    public function __construct(
        private readonly string $service,
        public readonly StorageException $exception,
    ) {}

    public function getService(): string
    {
        return $this->service;
    }
}
