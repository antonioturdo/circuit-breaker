<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\HalfOpen;

/**
 * The outcomes registered since the circuit became half-open.
 */
final class HalfOpenProgress
{
    /**
     * @param int $successes successful outcomes registered
     * @param int $failures  failed outcomes registered
     */
    public function __construct(
        public readonly int $successes,
        public readonly int $failures,
    ) {}
}
