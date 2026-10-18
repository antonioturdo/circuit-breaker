<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zeusi\CircuitBreaker\Counting\DurationBand;
use Zeusi\CircuitBreaker\Counting\DurationBands;
use Zeusi\CircuitBreaker\Outcome;
use Zeusi\CircuitBreaker\State;
use Zeusi\CircuitBreaker\Storage\CircuitState;
use Zeusi\CircuitBreaker\Storage\InMemoryStorage;
use Zeusi\CircuitBreaker\Storage\KeyScheme;
use Zeusi\CircuitBreaker\Storage\OutcomeCounter;
use Zeusi\CircuitBreaker\Tests\Fixtures\FakeClock;
use Zeusi\CircuitBreaker\Tests\Fixtures\OutcomeCountsFactory;

final class OutcomeCounterTest extends TestCase
{
    // a multiple of every bucket duration used below, so that bucket boundaries fall on round offsets
    private const START = 1_791_676_800_000;

    private FakeClock $clock;
    private InMemoryStorage $storage;
    private KeyScheme $keyScheme;
    private CircuitState $closedState;

    protected function setUp(): void
    {
        $this->clock = new FakeClock(self::START);
        $this->storage = new InMemoryStorage($this->clock);
        $this->keyScheme = new KeyScheme('payments');
        $this->closedState = new CircuitState(State::Closed, self::START);
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function bucketBoundaryProvider(): iterable
    {
        // window, offset of the counted instant from the recorded one, outcomes counted at that instant
        yield 'shortest window: 1 s buckets, same bucket' => [10_000, 899, 1];
        yield 'shortest window: 1 s buckets, next bucket' => [10_000, 900, 0];
        yield '60 s window: 1 s buckets, same bucket' => [60_000, 899, 1];
        yield '60 s window: 1 s buckets, next bucket' => [60_000, 900, 0];
        yield '90 s window: 1.5 s buckets, same bucket' => [90_000, 1_399, 1];
        yield '90 s window: 1.5 s buckets, next bucket' => [90_000, 1_400, 0];
        yield '600 s window: 10 s buckets, same bucket' => [600_000, 9_899, 1];
        yield '600 s window: 10 s buckets, next bucket' => [600_000, 9_900, 0];
    }

    #[DataProvider('bucketBoundaryProvider')]
    public function testBucketBoundary(int $window, int $countedOffset, int $expectedFailures): void
    {
        $counter = new OutcomeCounter($this->storage, $this->keyScheme, $this->closedState, $window, new DurationBands([]));

        // recorded 100 ms after the start of a bucket
        $counter->recordOutcome(Outcome::Failure, null, self::START + 100);
        $countedTime = self::START + 100 + $countedOffset;

        self::assertEquals(OutcomeCountsFactory::createWithoutDurations(0, $expectedFailures), $counter->countOutcomes($countedTime, $countedTime));
    }

    public function testCounting(): void
    {
        $counter = new OutcomeCounter($this->storage, $this->keyScheme, $this->closedState, 60_000, new DurationBands([]));
        $otherCounter = new OutcomeCounter($this->storage, $this->keyScheme, new CircuitState(State::HalfOpen, self::START + 10_000), 60_000, new DurationBands([]));

        $counter->recordOutcome(Outcome::Success, null, self::START);
        $counter->recordOutcome(Outcome::Success, null, self::START + 500);
        $counter->recordOutcome(Outcome::Failure, null, self::START + 1_500);
        $counter->recordOutcome(Outcome::Failure, null, self::START + 5_000);
        $otherCounter->recordOutcome(Outcome::Failure, null, self::START + 1_500);

        // successes and failures apart, every bucket of the range included, other periods excluded
        self::assertEquals(OutcomeCountsFactory::createWithoutDurations(2, 2), $counter->countOutcomes(self::START, self::START + 5_000));
        self::assertEquals(OutcomeCountsFactory::createWithoutDurations(0, 1), $counter->countOutcomes(self::START + 1_000, self::START + 4_999));
        self::assertEquals(OutcomeCountsFactory::createWithoutDurations(0, 1), $otherCounter->countOutcomes(self::START, self::START + 5_000));
        self::assertEquals(OutcomeCountsFactory::createWithoutDurations(0, 0), $counter->countOutcomes(self::START + 6_000, self::START + 9_000));
    }

    public function testOutcomeRemainsCountableForTheWholeWindow(): void
    {
        $counter = new OutcomeCounter($this->storage, $this->keyScheme, $this->closedState, 60_000, new DurationBands([]));
        $counter->recordOutcome(Outcome::Failure, null, self::START);

        // the last count whose window still includes the bucket of the outcome
        $this->clock->advance(60.999);
        $now = $this->clock->getCurrentTime();
        self::assertEquals(OutcomeCountsFactory::createWithoutDurations(0, 1), $counter->countOutcomes($now - 60_000, $now));

        // the bucket expires afterwards instead of staying in the storage
        $this->clock->advance(1.001);
        self::assertEquals(OutcomeCountsFactory::createWithoutDurations(0, 0), $counter->countOutcomes(self::START, self::START + 62_000));
    }

    public function testCountingDurations(): void
    {
        $counter = new OutcomeCounter($this->storage, $this->keyScheme, $this->closedState, 60_000, new DurationBands([1_000, 5_000]));

        $counter->recordOutcome(Outcome::Success, null, self::START);
        $counter->recordOutcome(Outcome::Success, 999, self::START);
        $counter->recordOutcome(Outcome::Success, 1_000, self::START);
        $counter->recordOutcome(Outcome::Failure, 5_000, self::START);

        // each outcome is counted in a single band: without a duration, below 1 second, below 5 seconds, from 5 seconds
        self::assertEquals(
            OutcomeCountsFactory::create([1_000, 5_000], successes: [1, 1, 1, 0], failures: [0, 0, 0, 1]),
            $counter->countOutcomes(self::START, self::START),
        );
    }

    public function testDurationsAreNotCountedWithoutThresholds(): void
    {
        $counter = new OutcomeCounter($this->storage, $this->keyScheme, $this->closedState, 60_000, new DurationBands([]));
        $successKey = $this->keyScheme->getOutcomeKey($this->closedState, Outcome::Success, new DurationBand(null, null), intdiv(self::START, 1_000));

        $counter->recordOutcome(Outcome::Success, 5_000, self::START);

        // the same key as without a duration
        self::assertEquals(OutcomeCountsFactory::createWithoutDurations(1, 0), $counter->countOutcomes(self::START, self::START));
        self::assertSame([$successKey => 1], $this->storage->fetch([$successKey]));
    }
}
