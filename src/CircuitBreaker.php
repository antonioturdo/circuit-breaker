<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker;

use Zeusi\CircuitBreaker\Clock\ClockInterface;
use Zeusi\CircuitBreaker\Clock\SystemClock;
use Zeusi\CircuitBreaker\Event\CallRejected;
use Zeusi\CircuitBreaker\Event\CircuitClosed;
use Zeusi\CircuitBreaker\Event\CircuitHalfOpened;
use Zeusi\CircuitBreaker\Event\CircuitOpened;
use Zeusi\CircuitBreaker\Event\EventInterface;
use Zeusi\CircuitBreaker\Event\EventListenerInterface;
use Zeusi\CircuitBreaker\Event\StorageFailed;
use Zeusi\CircuitBreaker\Exception\LogicException;
use Zeusi\CircuitBreaker\Exception\StorageException;
use Zeusi\CircuitBreaker\HalfOpen\HalfOpenProgress;
use Zeusi\CircuitBreaker\HalfOpen\PermissionRequest;
use Zeusi\CircuitBreaker\Storage\CircuitState;
use Zeusi\CircuitBreaker\Storage\KeyScheme;
use Zeusi\CircuitBreaker\Storage\OutcomeCounter;
use Zeusi\CircuitBreaker\Storage\OutcomeCounts;
use Zeusi\CircuitBreaker\Storage\StorageInterface;

/**
 * Circuit breaker of a single service, with its whole state in the storage.
 *
 * Transitions are executed lazily: every method that may write first brings the stored state up to date, executing
 * the transition due to time, if any, then does its own work. Every transition is a compare-and-set: the process winning
 * it emits the event. On storage errors, calls are permitted and a StorageFailed event is emitted.
 */
final class CircuitBreaker implements CircuitBreakerInterface
{
    private const STATE_TTL = 2_592_000;

    private readonly ClockInterface $clock;
    private readonly KeyScheme $keyScheme;
    private readonly int $timeWindow;
    private readonly int $openDuration;
    private readonly int $halfOpenMaxDuration;

    public function __construct(
        private readonly string $service,
        private readonly Configuration $configuration,
        private readonly StorageInterface $storage,
        private readonly ?EventListenerInterface $listener = null,
        ?ClockInterface $clock = null,
    ) {
        if (\PHP_INT_SIZE < 8) {
            throw new LogicException('The circuit breaker requires 64-bit PHP');
        }

        $this->keyScheme = new KeyScheme($service);
        $this->clock = $clock ?? new SystemClock();
        $this->timeWindow = self::secondsToMilliseconds($configuration->timeWindow);
        $this->openDuration = self::secondsToMilliseconds($configuration->openDuration);
        $this->halfOpenMaxDuration = self::secondsToMilliseconds($configuration->halfOpenPolicy->getMaxDuration());
    }

    public function getService(): string
    {
        return $this->service;
    }

    public function acquirePermission(): bool
    {
        try {
            $now = $this->clock->getCurrentTime();
            $currentState = $this->bringStateUpToDate($now);

            if ($currentState->state === State::Closed) {
                return true;
            }

            if ($currentState->state === State::HalfOpen && $this->acquireHalfOpenPermission($currentState, $now)) {
                return true;
            }

            $this->dispatchEvent(new CallRejected($this->service, $currentState->state));

            return false;
        } catch (StorageException $exception) {
            $this->dispatchEvent(new StorageFailed($this->service, $exception));

            return true;
        }
    }

    /**
     * Asks the policy whether the call goes through in the half-open state, counting it if the policy needs its number.
     *
     * @throws StorageException
     */
    private function acquireHalfOpenPermission(CircuitState $state, int $now): bool
    {
        $permissionRequest = new PermissionRequest(
            $now - $state->since,
            fn(): int => $this->storage->increment($this->keyScheme->getHalfOpenCallsKey($state), self::millisecondsToTtl($this->halfOpenMaxDuration)),
        );

        return $this->configuration->halfOpenPolicy->grantsPermission($permissionRequest);
    }

    /**
     * @return positive-int
     */
    private static function millisecondsToTtl(int $milliseconds): int
    {
        // never 0, which the storage does not accept
        return max(1, intdiv($milliseconds + 999, 1000));
    }

    public function registerSuccess(): void
    {
        $this->registerOutcome(Outcome::Success);
    }

    public function registerFailure(): void
    {
        $this->registerOutcome(Outcome::Failure);
    }

    public function getState(): State
    {
        $storedState = $this->loadStoredState();

        return ($this->computeTargetStateDueToTime($storedState, $this->clock->getCurrentTime()) ?? $storedState)->state;
    }

    private function registerOutcome(Outcome $outcome): void
    {
        try {
            $now = $this->clock->getCurrentTime();
            $currentState = $this->bringStateUpToDate($now);

            // a single counter, bound to the period of the current state, records and counts
            $outcomeCounter = $this->createOutcomeCounter($currentState);
            $outcomeCounter->recordOutcome($outcome, $now);

            $targetState = match ($currentState->state) {
                State::Closed => $this->computeTargetStateAfterOutcomeWhenClosed($outcomeCounter, $outcome, $now),
                State::HalfOpen => $this->computeTargetStateAfterOutcomeWhenHalfOpen($outcomeCounter, $now),
                // no call is permitted while open: the outcome belongs to a call permitted before opening, and is
                // recorded in the open period, which nothing counts
                State::Open => null,
            };

            if ($targetState !== null) {
                $this->tryStoreState($currentState, $targetState);
            }
        } catch (StorageException $exception) {
            $this->dispatchEvent(new StorageFailed($this->service, $exception));
        }
    }

    /**
     * Computes the transition of a closed circuit after an outcome.
     *
     * A failure opens the circuit when the time window holds enough outcomes and the failure rate reaches the threshold.
     *
     * @throws StorageException
     */
    private function computeTargetStateAfterOutcomeWhenClosed(OutcomeCounter $outcomeCounter, Outcome $outcome, int $now): ?CircuitState
    {
        if ($outcome === Outcome::Success) {
            return null;
        }

        $windowCounts = $this->countWindowOutcomes($outcomeCounter, $now);
        $hasEnoughOutcomes = $windowCounts->successes + $windowCounts->failures >= $this->configuration->minimumRequests;

        if (!$hasEnoughOutcomes) {
            return null;
        }

        if (!$this->reachesFailureRateThreshold($windowCounts)) {
            return null;
        }

        return new CircuitState(State::Open, $now);
    }

    /**
     * Computes the transition of a half-open circuit after an outcome.
     *
     * The circuit reopens as soon as its failure rate reaches the threshold, and closes early when the policy allows it
     * with the failure rate below the threshold.
     *
     * @throws StorageException
     */
    private function computeTargetStateAfterOutcomeWhenHalfOpen(OutcomeCounter $outcomeCounter, int $now): ?CircuitState
    {
        $halfOpenCounts = $this->countWindowOutcomes($outcomeCounter, $now);

        if ($this->reachesFailureRateThreshold($halfOpenCounts)) {
            return new CircuitState(State::Open, $now);
        }

        $halfOpenProgress = new HalfOpenProgress($halfOpenCounts->successes, $halfOpenCounts->failures);

        if ($this->configuration->halfOpenPolicy->allowsEarlyClosing($halfOpenProgress)) {
            return new CircuitState(State::Closed, $now);
        }

        return null;
    }

    /**
     * Brings the stored state up to date and returns it.
     *
     * Executes the transition due to time that nobody executed yet, if any, storing it and emitting its event.
     *
     * @throws StorageException
     */
    private function bringStateUpToDate(int $now): CircuitState
    {
        $storedState = $this->loadStoredState();
        $targetState = $this->computeTargetStateDueToTime($storedState, $now);

        if ($targetState === null) {
            return $storedState;
        }

        // when another process changed the state first, go on with the state it stored
        return $this->tryStoreState($storedState, $targetState) ? CircuitState::decode($targetState->encode()) : $this->loadStoredState();
    }

    /**
     * Computes the transition due to time, if any.
     *
     * At most one transition is due from any state.
     *
     * @throws StorageException
     */
    private function computeTargetStateDueToTime(CircuitState $storedState, int $now): ?CircuitState
    {
        // the half-open state would already be over, with no outcome since nobody called: it is skipped
        if ($storedState->state === State::Open && $now >= $storedState->since + $this->openDuration + $this->halfOpenMaxDuration) {
            return new CircuitState(State::Closed, $now);
        }

        if ($storedState->state === State::Open && $now >= $storedState->since + $this->openDuration) {
            return new CircuitState(State::HalfOpen, $storedState->since + $this->openDuration);
        }

        if ($storedState->state === State::HalfOpen && $now >= $storedState->since + $this->halfOpenMaxDuration) {
            if ($this->reachesFailureRateThreshold($this->countWindowOutcomes($this->createOutcomeCounter($storedState), $now))) {
                return new CircuitState(State::Open, $now);
            }

            return new CircuitState(State::Closed, $now);
        }

        return null;
    }

    /**
     * Stores the target state if the stored state is still the given one, and emits the event of the transition.
     *
     * @throws StorageException
     */
    private function tryStoreState(CircuitState $storedState, CircuitState $targetState): bool
    {
        if (!$this->storage->compareAndSet($this->keyScheme->getStateKey(), $storedState->storedValue, $targetState->encode(), self::STATE_TTL)) {
            return false;
        }

        $this->dispatchEvent(match ($targetState->state) {
            State::Open => new CircuitOpened($this->service, $storedState->state),
            State::HalfOpen => new CircuitHalfOpened($this->service),
            State::Closed => new CircuitClosed($this->service),
        });

        return true;
    }

    /**
     * @throws StorageException
     */
    private function loadStoredState(): CircuitState
    {
        $stateKey = $this->keyScheme->getStateKey();

        return CircuitState::decode($this->storage->fetch([$stateKey])[$stateKey] ?? null);
    }

    /**
     * Creates the outcome counter of the period spent in the given state.
     */
    private function createOutcomeCounter(CircuitState $state): OutcomeCounter
    {
        return new OutcomeCounter($this->storage, $this->keyScheme, $state, $this->timeWindow);
    }

    /**
     * Counts the outcomes of the time window.
     *
     * Only the outcomes of the period of the counter are counted: a half-open state never counts the failures that opened
     * the circuit, and a closed state never counts the outcomes of the half-open state before it.
     *
     * @throws StorageException
     */
    private function countWindowOutcomes(OutcomeCounter $outcomeCounter, int $now): OutcomeCounts
    {
        return $outcomeCounter->countOutcomes($now - $this->timeWindow, $now);
    }

    private function reachesFailureRateThreshold(OutcomeCounts $counts): bool
    {
        $total = $counts->successes + $counts->failures;

        return $total > 0 && $counts->failures * 100 >= $this->configuration->failureRateThreshold * $total;
    }

    private function dispatchEvent(EventInterface $event): void
    {
        try {
            $this->listener?->handleEvent($event);
        } catch (\Throwable) {
            // a failing listener never makes the protected call fail nor leaves a transition half done
        }
    }

    private static function secondsToMilliseconds(float $seconds): int
    {
        return (int) round($seconds * 1000);
    }
}
