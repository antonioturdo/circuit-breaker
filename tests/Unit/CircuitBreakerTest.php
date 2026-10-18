<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zeusi\CircuitBreaker\CircuitBreaker;
use Zeusi\CircuitBreaker\Configuration;
use Zeusi\CircuitBreaker\Event\CallRejected;
use Zeusi\CircuitBreaker\Event\CircuitClosed;
use Zeusi\CircuitBreaker\Event\CircuitHalfOpened;
use Zeusi\CircuitBreaker\Event\CircuitOpened;
use Zeusi\CircuitBreaker\Event\EventListenerInterface;
use Zeusi\CircuitBreaker\Event\StorageFailed;
use Zeusi\CircuitBreaker\Exception\InvalidArgumentException;
use Zeusi\CircuitBreaker\Exception\StorageException;
use Zeusi\CircuitBreaker\HalfOpen\HalfOpenPolicyInterface;
use Zeusi\CircuitBreaker\HalfOpen\PermittedCallsPolicy;
use Zeusi\CircuitBreaker\HalfOpen\RampCurveInterface;
use Zeusi\CircuitBreaker\HalfOpen\RampPolicy;
use Zeusi\CircuitBreaker\Opening\FailureOrSlowCallRateRule;
use Zeusi\CircuitBreaker\Opening\FailureRateRule;
use Zeusi\CircuitBreaker\Opening\OpeningCause;
use Zeusi\CircuitBreaker\Opening\SlowCallRateRule;
use Zeusi\CircuitBreaker\State;
use Zeusi\CircuitBreaker\Storage\InMemoryStorage;
use Zeusi\CircuitBreaker\Storage\StorageInterface;
use Zeusi\CircuitBreaker\Tests\Fixtures\FakeClock;
use Zeusi\CircuitBreaker\Tests\Fixtures\InterceptingStorage;
use Zeusi\CircuitBreaker\Tests\Fixtures\RecordingEventListener;

final class CircuitBreakerTest extends TestCase
{
    private FakeClock $clock;
    private InMemoryStorage $storage;
    private RecordingEventListener $listener;

    protected function setUp(): void
    {
        $this->clock = new FakeClock();
        $this->storage = new InMemoryStorage($this->clock);
        $this->listener = new RecordingEventListener();
    }

    /**
     * @return iterable<string, array{int, int, State}>
     */
    public static function closedOutcomesProvider(): iterable
    {
        yield 'failures below the minimum requests' => [0, 9, State::Closed];
        yield 'failure rate below the threshold' => [6, 4, State::Closed];
        yield 'failure rate at the threshold' => [5, 5, State::Open];
        yield 'only failures' => [0, 10, State::Open];
    }

    #[DataProvider('closedOutcomesProvider')]
    public function testOpening(int $successes, int $failures, State $expected): void
    {
        $breaker = $this->breaker();

        $this->register($breaker, $successes, $failures);

        self::assertSame($expected, $breaker->getState());
    }

    public function testAnyOpeningRuleOpensTheCircuit(): void
    {
        $configuration = new Configuration(
            timeWindow: 60,
            openingRules: [new FailureRateRule(threshold: 50, minimumRequests: 100), new FailureRateRule(threshold: 90, minimumRequests: 5)],
            openDuration: 10,
            halfOpenPolicy: RampPolicy::createExponential(60, 0.01),
        );
        $breaker = new CircuitBreaker('payments', $configuration, $this->storage, $this->listener, $this->clock);

        // durations are accepted, though no configured rule decides on them
        $breaker->registerSuccess(0.25);
        $breaker->registerFailure(2.5);
        $this->register($breaker, 0, 7);
        self::assertSame(State::Closed, $breaker->getState());

        // 9 failures out of 10 reach the second rule only
        $breaker->registerFailure();
        self::assertSame(State::Open, $breaker->getState());
    }

    public function testSlowCallsOpenTheCircuit(): void
    {
        $breaker = $this->breakerWithSlowCallRule(PermittedCallsPolicy::createSingleTrial(30));

        // outcomes without a duration do not dilute the slow call rate
        $this->register($breaker, 10, 0);
        $breaker->registerSuccess(0.1);
        $breaker->registerSuccess(2.0);
        $breaker->registerSuccess(2.0);
        $breaker->registerSuccess(2.0);
        self::assertSame(State::Closed, $breaker->getState());

        // 4 slow calls out of 5 with a duration: a success opens the circuit
        $breaker->registerSuccess(2.5);
        self::assertSame(State::Open, $breaker->getState());
        self::assertEquals([new CircuitOpened('payments', State::Closed, new OpeningCause(self::slowCallRule(), 4, 5))], $this->listener->flush());

        // while half-open, a single slow success reopens
        $this->clock->advance(10);
        self::assertTrue($breaker->acquirePermission());
        $breaker->registerSuccess(3.0);
        self::assertEquals([new CircuitHalfOpened('payments'), new CircuitOpened('payments', State::HalfOpen, new OpeningCause(self::slowCallRule(), 1, 1))], $this->listener->flush());
    }

    public function testSlowCallRulesWithDifferentDurations(): void
    {
        $verySlowCallRule = new SlowCallRateRule(slowCallDuration: 5.0, threshold: 50, minimumRequests: 4);
        $slowCallRule = new SlowCallRateRule(slowCallDuration: 1.0, threshold: 75, minimumRequests: 4);
        $configuration = new Configuration(
            timeWindow: 60,
            openingRules: [self::failureRateRule(), $verySlowCallRule, $slowCallRule],
            openDuration: 10,
            halfOpenPolicy: RampPolicy::createExponential(60, 0.01),
        );
        $breaker = new CircuitBreaker('payments', $configuration, $this->storage, $this->listener, $this->clock);

        // calls of 2 seconds are slow for the 1 second rule only
        $breaker->registerSuccess(0.5);
        $breaker->registerSuccess(2.0);
        $breaker->registerSuccess(2.0);
        $breaker->registerSuccess(2.0);
        self::assertSame(State::Open, $breaker->getState());
        self::assertEquals([new CircuitOpened('payments', State::Closed, new OpeningCause($slowCallRule, 3, 4))], $this->listener->flush());
    }

    public function testFailedOrSlowCallsNextToFailuresOnly(): void
    {
        $failureOrSlowCallRule = new FailureOrSlowCallRateRule(slowCallDuration: 2.0, threshold: 75, minimumRequests: 4);
        $configuration = new Configuration(
            timeWindow: 60,
            openingRules: [new FailureRateRule(threshold: 50, minimumRequests: 4), $failureOrSlowCallRule],
            openDuration: 10,
            halfOpenPolicy: RampPolicy::createExponential(60, 0.01),
        );
        $breaker = new CircuitBreaker('payments', $configuration, $this->storage, $this->listener, $this->clock);

        // 1 failure out of 4 stays below the failure rate rule, 3 failed or slow calls out of 4 reach the other one
        $breaker->registerFailure();
        $breaker->registerSuccess(0.5);
        $breaker->registerSuccess(2.0);
        self::assertSame(State::Closed, $breaker->getState());
        $breaker->registerSuccess(3.0);
        self::assertEquals([new CircuitOpened('payments', State::Closed, new OpeningCause($failureOrSlowCallRule, 3, 4))], $this->listener->flush());
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function invalidDurationProvider(): iterable
    {
        yield 'negative' => [-0.001];
        yield 'infinite' => [\INF];
        yield 'not a number' => [\NAN];
    }

    #[DataProvider('invalidDurationProvider')]
    public function testInvalidDuration(float $duration): void
    {
        $breaker = $this->breaker();

        try {
            $breaker->registerSuccess($duration);
            self::fail('A success with an invalid duration was registered');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(InvalidArgumentException::class);
        $breaker->registerFailure($duration);
    }

    public function testOpenCircuitRejectsCalls(): void
    {
        $breaker = $this->breaker();

        $this->register($breaker, 0, 12);

        // outcomes arriving while open are ignored: a single opening
        self::assertEquals([new CircuitOpened('payments', State::Closed, new OpeningCause(self::failureRateRule(), 10, 10))], $this->listener->flush());
        self::assertFalse($breaker->acquirePermission());
        self::assertEquals([new CallRejected('payments', State::Open)], $this->listener->flush());
        self::assertSame(State::Open, $breaker->getState());
    }

    public function testLateOutcomesWhileOpenAreNotCounted(): void
    {
        $breaker = $this->breaker(PermittedCallsPolicy::createSingleTrial(30));
        $this->open($breaker);

        // a call permitted before opening fails while open: it must not weigh on the coming half-open state
        $this->clock->advance(5);
        $breaker->registerFailure();

        $this->clock->advance(5);
        self::assertTrue($breaker->acquirePermission());
        $breaker->registerSuccess();

        self::assertSame(State::Closed, $breaker->getState());
    }

    public function testFailuresLeaveTheWindow(): void
    {
        $breaker = $this->breaker();

        $this->register($breaker, 0, 9);
        $this->clock->advance(61);
        $this->register($breaker, 0, 1);
        self::assertSame(State::Closed, $breaker->getState());

        $this->register($breaker, 0, 9);
        self::assertSame(State::Open, $breaker->getState());
    }

    public function testSuccessfulTrialClosesTheCircuit(): void
    {
        $breaker = $this->breaker(PermittedCallsPolicy::createSingleTrial(30));
        $this->open($breaker);

        $this->clock->advance(9.999);
        self::assertFalse($breaker->acquirePermission());
        $this->listener->flush();

        $this->clock->advance(0.001);
        self::assertTrue($breaker->acquirePermission());
        self::assertEquals([new CircuitHalfOpened('payments')], $this->listener->flush());

        // a single trial
        self::assertFalse($breaker->acquirePermission());
        self::assertEquals([new CallRejected('payments', State::HalfOpen)], $this->listener->flush());

        $breaker->registerSuccess();
        self::assertEquals([new CircuitClosed('payments')], $this->listener->flush());
        self::assertSame(State::Closed, $breaker->getState());

        // the failures that opened the circuit no longer count
        $this->register($breaker, 0, 1);
        self::assertSame(State::Closed, $breaker->getState());
        self::assertTrue($breaker->acquirePermission());
    }

    public function testClosedCircuitOpensAgain(): void
    {
        $breaker = $this->breaker(PermittedCallsPolicy::createSingleTrial(30));
        $this->open($breaker);
        $this->clock->advance(10);
        self::assertTrue($breaker->acquirePermission());
        $breaker->registerSuccess();
        self::assertSame(State::Closed, $breaker->getState());
        $this->listener->flush();

        // the closed state counts the outcomes of its own period: new failures open the circuit again, even once
        // the failures of the first opening have left the time window
        $this->clock->advance(61);
        $this->register($breaker, 0, 10);

        self::assertSame(State::Open, $breaker->getState());
        self::assertEquals([new CircuitOpened('payments', State::Closed, new OpeningCause(self::failureRateRule(), 10, 10))], $this->listener->flush());
    }

    public function testFailedTrialReopensTheCircuit(): void
    {
        $breaker = $this->breaker(PermittedCallsPolicy::createSingleTrial(30));
        $this->open($breaker);

        $this->clock->advance(10);
        self::assertTrue($breaker->acquirePermission());
        $breaker->registerFailure();

        self::assertEquals([new CircuitHalfOpened('payments'), new CircuitOpened('payments', State::HalfOpen, new OpeningCause(self::failureRateRule(), 1, 1))], $this->listener->flush());
        self::assertFalse($breaker->acquirePermission());

        $this->clock->advance(10);
        self::assertTrue($breaker->acquirePermission());
        self::assertEquals([new CallRejected('payments', State::Open), new CircuitHalfOpened('payments')], $this->listener->flush());
    }

    public function testHalfOpenStateEndsAtItsMaximumDuration(): void
    {
        $breaker = $this->breaker(PermittedCallsPolicy::createSingleTrial(30));
        $this->open($breaker);

        // the trial never reports back
        $this->clock->advance(10);
        self::assertTrue($breaker->acquirePermission());
        $this->clock->advance(29.999);
        self::assertFalse($breaker->acquirePermission());
        $this->listener->flush();

        $this->clock->advance(0.001);
        self::assertTrue($breaker->acquirePermission());
        self::assertEquals([new CircuitClosed('payments')], $this->listener->flush());
        self::assertSame(State::Closed, $breaker->getState());
    }

    public function testRampFollowsItsCurveUntilItsEnd(): void
    {
        $curve = self::createStub(RampCurveInterface::class);
        $curve->method('computeAdmissionRatio')->willReturn(0.0);
        $breaker = $this->breaker(new RampPolicy(60, $curve));
        $this->open($breaker);

        $this->clock->advance(10);
        self::assertFalse($breaker->acquirePermission());
        self::assertEquals([new CircuitHalfOpened('payments'), new CallRejected('payments', State::HalfOpen)], $this->listener->flush());

        $this->clock->advance(60);
        self::assertTrue($breaker->acquirePermission());
        self::assertEquals([new CircuitClosed('payments')], $this->listener->flush());
    }

    public function testLongIdlePeriodSkipsTheHalfOpenState(): void
    {
        $breaker = $this->breaker();
        $this->open($breaker);

        // the ramp of the default test configuration lasts 60 s
        $this->clock->advance(10 + 59.999);
        self::assertSame(State::HalfOpen, $breaker->getState());
        $this->clock->advance(0.001 + 3600);

        // the effective state, without writing nor emitting events
        self::assertSame(State::Closed, $breaker->getState());
        self::assertSame([], $this->listener->flush());

        // the half-open state would already be over, with no outcome: it is skipped
        self::assertTrue($breaker->acquirePermission());
        self::assertEquals([new CircuitClosed('payments')], $this->listener->flush());
    }

    public function testEffectiveStateBeforeTheTransition(): void
    {
        $breaker = $this->breaker(PermittedCallsPolicy::createSingleTrial(30));
        $this->open($breaker);

        $this->clock->advance(10);
        self::assertSame(State::HalfOpen, $breaker->getState());
        self::assertSame([], $this->listener->flush());

        self::assertTrue($breaker->acquirePermission());
        self::assertEquals([new CircuitHalfOpened('payments')], $this->listener->flush());
    }

    public function testLostTransitionGoesOnWithTheWinnerState(): void
    {
        $storage = new InterceptingStorage($this->storage);
        $configuration = $this->configuration(PermittedCallsPolicy::createSingleTrial(30));
        $breaker = new CircuitBreaker('payments', $configuration, $storage, $this->listener, $this->clock);
        $otherListener = new RecordingEventListener();
        $other = new CircuitBreaker('payments', $configuration, $storage, $otherListener, $this->clock);
        $this->open($breaker);
        $this->clock->advance(10);

        // right before this process, another one makes the transition to half-open and closes the circuit with a
        // successful trial: this process loses its compare-and-set and must go on with the closed state
        $storage->beforeNextCompareAndSet(static function () use ($other): void {
            self::assertTrue($other->acquirePermission());
            $other->registerSuccess();
        });

        self::assertTrue($breaker->acquirePermission());
        self::assertEquals([new CircuitHalfOpened('payments'), new CircuitClosed('payments')], $otherListener->flush());
        self::assertSame([], $this->listener->flush());
    }

    public function testHalfOpenStateEndingAboveTheThresholdReopens(): void
    {
        $storage = new InterceptingStorage($this->storage);
        $breaker = new CircuitBreaker('payments', $this->configuration(PermittedCallsPolicy::createSingleTrial(30)), $storage, $this->listener, $this->clock);
        $this->open($breaker);
        $this->clock->advance(10);
        self::assertTrue($breaker->acquirePermission());
        $this->listener->flush();

        // the failed trial is recorded, but the immediate reopening fails
        $storage->beforeNextCompareAndSet(static fn() => throw new StorageException('Connection lost'));
        $breaker->registerFailure();
        self::assertSame(State::HalfOpen, $breaker->getState());
        $this->listener->flush();

        // the half-open state ends above the threshold: it reopens instead of closing
        $this->clock->advance(30);
        self::assertFalse($breaker->acquirePermission());
        self::assertEquals([new CircuitOpened('payments', State::HalfOpen, new OpeningCause(self::failureRateRule(), 1, 1)), new CallRejected('payments', State::Open)], $this->listener->flush());
    }

    public function testStorageErrorsPermitCalls(): void
    {
        $storage = self::createStub(StorageInterface::class);
        $storage->method('fetch')->willThrowException(new StorageException('Connection refused'));
        $breaker = new CircuitBreaker('payments', $this->configuration(), $storage, $this->listener, $this->clock);

        self::assertTrue($breaker->acquirePermission());
        $breaker->registerFailure();
        $events = $this->listener->flush();
        self::assertCount(2, $events);
        self::assertContainsOnlyInstancesOf(StorageFailed::class, $events);

        // a diagnostic read does not hide the problem
        $this->expectException(StorageException::class);
        $breaker->getState();
    }

    public function testListenerErrorsAreIgnored(): void
    {
        $listener = self::createStub(EventListenerInterface::class);
        $listener->method('handleEvent')->willThrowException(new \RuntimeException('Listener failure'));
        $breaker = new CircuitBreaker('payments', $this->configuration(), $this->storage, $listener, $this->clock);

        $this->register($breaker, 0, 10);

        self::assertSame(State::Open, $breaker->getState());
        self::assertFalse($breaker->acquirePermission());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidServiceProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'opening brace' => ['pay{ments'];
        yield 'closing brace' => ['pay}ments'];
    }

    #[DataProvider('invalidServiceProvider')]
    public function testInvalidService(string $service): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CircuitBreaker($service, $this->configuration(), $this->storage);
    }

    private function configuration(?HalfOpenPolicyInterface $halfOpenPolicy = null): Configuration
    {
        return new Configuration(timeWindow: 60, openingRules: [self::failureRateRule()], openDuration: 10, halfOpenPolicy: $halfOpenPolicy ?? RampPolicy::createExponential(60, 0.01));
    }

    private function breakerWithSlowCallRule(HalfOpenPolicyInterface $halfOpenPolicy): CircuitBreaker
    {
        $configuration = new Configuration(
            timeWindow: 60,
            openingRules: [self::failureRateRule(), self::slowCallRule()],
            openDuration: 10,
            halfOpenPolicy: $halfOpenPolicy,
        );

        return new CircuitBreaker('payments', $configuration, $this->storage, $this->listener, $this->clock);
    }

    private static function failureRateRule(): FailureRateRule
    {
        return new FailureRateRule(threshold: 50, minimumRequests: 10);
    }

    private static function slowCallRule(): SlowCallRateRule
    {
        return new SlowCallRateRule(slowCallDuration: 2.0, threshold: 80, minimumRequests: 5);
    }

    private function breaker(?HalfOpenPolicyInterface $halfOpenPolicy = null): CircuitBreaker
    {
        return new CircuitBreaker('payments', $this->configuration($halfOpenPolicy), $this->storage, $this->listener, $this->clock);
    }

    private function register(CircuitBreaker $breaker, int $successes, int $failures): void
    {
        for ($i = 0; $i < $successes; $i++) {
            $breaker->registerSuccess();
        }

        for ($i = 0; $i < $failures; $i++) {
            $breaker->registerFailure();
        }
    }

    private function open(CircuitBreaker $breaker): void
    {
        $this->register($breaker, 0, 10);
        $this->listener->flush();
    }
}
