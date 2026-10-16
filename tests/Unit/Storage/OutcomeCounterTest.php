<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zeusi\CircuitBreaker\Outcome;
use Zeusi\CircuitBreaker\State;
use Zeusi\CircuitBreaker\Storage\CircuitState;
use Zeusi\CircuitBreaker\Storage\InMemoryStorage;
use Zeusi\CircuitBreaker\Storage\KeyScheme;
use Zeusi\CircuitBreaker\Storage\OutcomeCounter;
use Zeusi\CircuitBreaker\Storage\OutcomeCounts;
use Zeusi\CircuitBreaker\Tests\Fixtures\FakeClock;

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
        $counter = new OutcomeCounter($this->storage, $this->keyScheme, $this->closedState, $window);

        // recorded 100 ms after the start of a bucket
        $counter->recordOutcome(Outcome::Failure, self::START + 100);
        $countedTime = self::START + 100 + $countedOffset;

        self::assertEquals(new OutcomeCounts(0, $expectedFailures), $counter->countOutcomes($countedTime, $countedTime));
    }

    public function testCounting(): void
    {
        $counter = new OutcomeCounter($this->storage, $this->keyScheme, $this->closedState, 60_000);
        $otherCounter = new OutcomeCounter($this->storage, $this->keyScheme, new CircuitState(State::HalfOpen, self::START + 10_000), 60_000);

        $counter->recordOutcome(Outcome::Success, self::START);
        $counter->recordOutcome(Outcome::Success, self::START + 500);
        $counter->recordOutcome(Outcome::Failure, self::START + 1_500);
        $counter->recordOutcome(Outcome::Failure, self::START + 5_000);
        $otherCounter->recordOutcome(Outcome::Failure, self::START + 1_500);

        // successes and failures apart, every bucket of the range included, other periods excluded
        self::assertEquals(new OutcomeCounts(2, 2), $counter->countOutcomes(self::START, self::START + 5_000));
        self::assertEquals(new OutcomeCounts(0, 1), $counter->countOutcomes(self::START + 1_000, self::START + 4_999));
        self::assertEquals(new OutcomeCounts(0, 1), $otherCounter->countOutcomes(self::START, self::START + 5_000));
        self::assertEquals(new OutcomeCounts(0, 0), $counter->countOutcomes(self::START + 6_000, self::START + 9_000));
    }

    public function testOutcomeRemainsCountableForTheWholeWindow(): void
    {
        $counter = new OutcomeCounter($this->storage, $this->keyScheme, $this->closedState, 60_000);
        $counter->recordOutcome(Outcome::Failure, self::START);

        // the last count whose window still includes the bucket of the outcome
        $this->clock->advance(60.999);
        $now = $this->clock->getCurrentTime();
        self::assertEquals(new OutcomeCounts(0, 1), $counter->countOutcomes($now - 60_000, $now));

        // the bucket expires afterwards instead of staying in the storage
        $this->clock->advance(1.001);
        self::assertEquals(new OutcomeCounts(0, 0), $counter->countOutcomes(self::START, self::START + 62_000));
    }
}
