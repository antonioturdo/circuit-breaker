<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Exception;

/**
 * The circuit breaker did not permit the call: the circuit is open, or half-open and the call was not let through.
 */
final class CallNotPermittedException extends \RuntimeException implements ExceptionInterface
{
    public function __construct(
        public readonly string $service,
    ) {
        parent::__construct(\sprintf('The circuit breaker of "%s" did not permit the call', $service));
    }
}
