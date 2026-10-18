<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker;

use Zeusi\CircuitBreaker\Clock\ClockInterface;
use Zeusi\CircuitBreaker\Clock\SystemClock;
use Zeusi\CircuitBreaker\Counting\DurationBands;
use Zeusi\CircuitBreaker\Counting\OutcomeCounts;
use Zeusi\CircuitBreaker\Event\CallRejected;
use Zeusi\CircuitBreaker\Event\CircuitClosed;
use Zeusi\CircuitBreaker\Event\CircuitHalfOpened;
use Zeusi\CircuitBreaker\Event\CircuitOpened;
use Zeusi\CircuitBreaker\Event\EventInterface;
use Zeusi\CircuitBreaker\Event\EventListenerInterface;
use Zeusi\CircuitBreaker\Event\StorageFailed;
use Zeusi\CircuitBreaker\Exception\InvalidArgumentException;
use Zeusi\CircuitBreaker\Exception\LogicException;
use Zeusi\CircuitBreaker\Exception\StorageException;
use Zeusi\CircuitBreaker\HalfOpen\HalfOpenProgress;
use Zeusi\CircuitBreaker\HalfOpen\PermissionRequest;
use Zeusi\CircuitBreaker\Opening\OpeningCause;
use Zeusi\CircuitBreaker\Opening\OpeningRuleInterface;
use Zeusi\CircuitBreaker\Storage\CircuitState;
use Zeusi\CircuitBreaker\Storage\KeyScheme;
use Zeusi\CircuitBreaker\Storage\OutcomeCounter;
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

    private readonly DurationBands $durationBands;

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
        // outcomes are counted in the bands delimited by the duration thresholds of all the rules
        $this->durationBands = new DurationBands(array_merge([], ...array_map(
            static fn(OpeningRuleInterface $openingRule): array => $openingRule->getDurationThresholds(),
            $configuration->openingRules,
        )));
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

    public function registerSuccess(?float $duration = null): void
    {
        self::assertValidDuration($duration);
        $this->registerOutcome(Outcome::Success, $duration === null ? null : self::secondsToMilliseconds($duration));
    }

    public function registerFailure(?float $duration = null): void
    {
        self::assertValidDuration($duration);
        $this->registerOutcome(Outcome::Failure, $duration === null ? null : self::secondsToMilliseconds($duration));
    }

    public function getState(): State
    {
        $storedState = $this->loadStoredState();

        return ($this->computeTransitionDueToTime($storedState, $this->clock->getCurrentTime())->targetState ?? $storedState)->state;
    }

    /**
     * @param int|null $duration milliseconds the call took, null when unknown
     */
    private function registerOutcome(Outcome $outcome, ?int $duration): void
    {
        try {
            $now = $this->clock->getCurrentTime();
            $currentState = $this->bringStateUpToDate($now);

            // a single counter, bound to the period of the current state, records and counts
            $outcomeCounter = $this->createOutcomeCounter($currentState);
            $outcomeCounter->recordOutcome($outcome, $duration, $now);

            $transition = match ($currentState->state) {
                State::Closed => $this->computeTransitionAfterOutcomeWhenClosed($outcomeCounter, $outcome, $duration, $now),
                State::HalfOpen => $this->computeTransitionAfterOutcomeWhenHalfOpen($outcomeCounter, $now),
                // no call is permitted while open: the outcome belongs to a call permitted before opening, and is
                // recorded in the open period, which nothing counts
                State::Open => null,
            };

            if ($transition !== null) {
                $this->tryStoreState($currentState, $transition);
            }
        } catch (StorageException $exception) {
            $this->dispatchEvent(new StorageFailed($this->service, $exception));
        }
    }

    /**
     * Computes the transition of a closed circuit after an outcome.
     *
     * A failure or a slow call opens the circuit when an opening rule requires it. A fast success lowers every rate,
     * so it cannot.
     *
     * @param int|null $duration milliseconds the call took, null when unknown
     *
     * @throws StorageException
     */
    private function computeTransitionAfterOutcomeWhenClosed(OutcomeCounter $outcomeCounter, Outcome $outcome, ?int $duration, int $now): ?Transition
    {
        if ($outcome === Outcome::Success && !$this->isSlow($duration)) {
            return null;
        }

        $openingCause = $this->findOpeningCause($this->countWindowOutcomes($outcomeCounter, $now), State::Closed);

        if ($openingCause === null) {
            return null;
        }

        return Transition::createOpening($now, $openingCause);
    }

    /**
     * Checks whether a duration reaches a duration threshold of the rules.
     *
     * @param int|null $duration milliseconds the call took, null when unknown
     */
    private function isSlow(?int $duration): bool
    {
        // the first band with a duration starts from 0, the others from a threshold
        return $this->durationBands->getBand($duration)->lowerBound > 0;
    }

    /**
     * Computes the transition of a half-open circuit after an outcome.
     *
     * The circuit reopens as soon as an opening rule requires it, and closes early when the policy allows it otherwise.
     *
     * @throws StorageException
     */
    private function computeTransitionAfterOutcomeWhenHalfOpen(OutcomeCounter $outcomeCounter, int $now): ?Transition
    {
        $halfOpenCounts = $this->countWindowOutcomes($outcomeCounter, $now);
        $openingCause = $this->findOpeningCause($halfOpenCounts, State::HalfOpen);

        if ($openingCause !== null) {
            return Transition::createOpening($now, $openingCause);
        }

        $halfOpenProgress = new HalfOpenProgress($halfOpenCounts->getOutcomes());

        if ($this->configuration->halfOpenPolicy->allowsEarlyClosing($halfOpenProgress)) {
            return Transition::createClosing($now);
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
        $transition = $this->computeTransitionDueToTime($storedState, $now);

        if ($transition === null) {
            return $storedState;
        }

        // when another process changed the state first, go on with the state it stored
        return $this->tryStoreState($storedState, $transition) ? CircuitState::decode($transition->targetState->encode()) : $this->loadStoredState();
    }

    /**
     * Computes the transition due to time, if any.
     *
     * At most one transition is due from any state.
     *
     * @throws StorageException
     */
    private function computeTransitionDueToTime(CircuitState $storedState, int $now): ?Transition
    {
        // the half-open state would already be over, with no outcome since nobody called: it is skipped
        if ($storedState->state === State::Open && $now >= $storedState->since + $this->openDuration + $this->halfOpenMaxDuration) {
            return Transition::createClosing($now);
        }

        if ($storedState->state === State::Open && $now >= $storedState->since + $this->openDuration) {
            return Transition::createHalfOpening($storedState->since + $this->openDuration);
        }

        if ($storedState->state === State::HalfOpen && $now >= $storedState->since + $this->halfOpenMaxDuration) {
            $openingCause = $this->findOpeningCause($this->countWindowOutcomes($this->createOutcomeCounter($storedState), $now), State::HalfOpen);

            return $openingCause === null ? Transition::createClosing($now) : Transition::createOpening($now, $openingCause);
        }

        return null;
    }

    /**
     * Stores the target state of the transition if the stored state is still the given one, and emits its event.
     *
     * @throws StorageException
     */
    private function tryStoreState(CircuitState $storedState, Transition $transition): bool
    {
        if (!$this->storage->compareAndSet($this->keyScheme->getStateKey(), $storedState->storedValue, $transition->targetState->encode(), self::STATE_TTL)) {
            return false;
        }

        $this->dispatchEvent(match ($transition->targetState->state) {
            State::Open => new CircuitOpened($this->service, $storedState->state, $transition->getOpeningCause()),
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
        return new OutcomeCounter($this->storage, $this->keyScheme, $state, $this->timeWindow, $this->durationBands);
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

    /**
     * Finds the first opening rule requiring the circuit to open, given the outcomes counted in the given state.
     */
    private function findOpeningCause(OutcomeCounts $counts, State $state): ?OpeningCause
    {
        foreach ($this->configuration->openingRules as $openingRule) {
            $openingCause = $openingRule->findOpeningCause($counts, $state);

            if ($openingCause !== null) {
                return $openingCause;
            }
        }

        return null;
    }

    private function dispatchEvent(EventInterface $event): void
    {
        try {
            $this->listener?->handleEvent($event);
        } catch (\Throwable) {
            // a failing listener never makes the protected call fail nor leaves a transition half done
        }
    }

    /**
     * @throws InvalidArgumentException if the duration is negative or not finite
     */
    private static function assertValidDuration(?float $duration): void
    {
        if ($duration !== null && (!is_finite($duration) || $duration < 0.0)) {
            throw new InvalidArgumentException(\sprintf('The duration must be a finite number of seconds, at least 0, %s given', $duration));
        }
    }

    private static function secondsToMilliseconds(float $seconds): int
    {
        return (int) round($seconds * 1000);
    }
}
