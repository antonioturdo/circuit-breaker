<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\HalfOpen;

use Zeusi\CircuitBreaker\Exception\StorageException;

/**
 * A call asking for permission while the circuit is half-open.
 */
final class PermissionRequest
{
    private ?int $callNumber = null;

    /**
     * @param int             $elapsed   milliseconds since the circuit became half-open
     * @param \Closure(): int $countCall counts the call, returning its number
     */
    public function __construct(
        public readonly int $elapsed,
        private readonly \Closure $countCall,
    ) {}

    /**
     * Gets the number of the call since the circuit became half-open.
     *
     * The call is counted the first time its number is read, and keeps it afterwards, so only the calls whose number is
     * read are counted: a policy reading it for every call numbers every call, one that never reads it costs no storage
     * write. Numbers start from 1 and are unique even with concurrent calls.
     *
     * @throws StorageException
     */
    public function getCallNumber(): int
    {
        return $this->callNumber ??= ($this->countCall)();
    }
}
