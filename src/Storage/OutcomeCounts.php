<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Storage;

/**
 * @internal
 */
final class OutcomeCounts
{
    public function __construct(
        public readonly int $successes,
        public readonly int $failures,
    ) {}
}
