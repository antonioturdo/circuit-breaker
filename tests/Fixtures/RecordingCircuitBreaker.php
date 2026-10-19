<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Tests\Fixtures;

use Zeusi\CircuitBreaker\CircuitBreakerInterface;
use Zeusi\CircuitBreaker\Outcome;
use Zeusi\CircuitBreaker\State;

/**
 * Circuit breaker permitting calls or not, as told, and recording the outcomes registered.
 */
final class RecordingCircuitBreaker implements CircuitBreakerInterface
{
    /** @var list<array{Outcome, float|null}> */
    private array $outcomes = [];

    public function __construct(
        private readonly bool $permitsCalls,
    ) {}

    public function getService(): string
    {
        return 'payments';
    }

    public function acquirePermission(): bool
    {
        return $this->permitsCalls;
    }

    public function registerSuccess(?float $duration = null): void
    {
        $this->outcomes[] = [Outcome::Success, $duration];
    }

    public function registerFailure(?float $duration = null): void
    {
        $this->outcomes[] = [Outcome::Failure, $duration];
    }

    public function getState(): State
    {
        return $this->permitsCalls ? State::Closed : State::Open;
    }

    /**
     * @return list<array{Outcome, float|null}> the outcomes registered, with their durations
     */
    public function getOutcomes(): array
    {
        return $this->outcomes;
    }
}
