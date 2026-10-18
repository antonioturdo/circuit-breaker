<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Tests\Unit\Opening;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zeusi\CircuitBreaker\Counting\OutcomeCounts;
use Zeusi\CircuitBreaker\Exception\InvalidArgumentException;
use Zeusi\CircuitBreaker\Opening\OpeningCause;
use Zeusi\CircuitBreaker\Opening\SlowCallRateRule;
use Zeusi\CircuitBreaker\State;
use Zeusi\CircuitBreaker\Tests\Fixtures\OutcomeCountsFactory;

final class SlowCallRateRuleTest extends TestCase
{
    /**
     * @return iterable<string, array{OutcomeCounts, State, array{int, int}|null}>
     */
    public static function openingProvider(): iterable
    {
        // bands: without a duration, below 2 seconds, from 2 seconds
        yield 'below the minimum requests' => [OutcomeCountsFactory::create([2_000], successes: [0, 0, 4], failures: [0, 0, 0]), State::Closed, null];
        yield 'at the threshold' => [OutcomeCountsFactory::create([2_000], successes: [0, 1, 4], failures: [0, 0, 0]), State::Closed, [4, 5]];
        yield 'below the threshold' => [OutcomeCountsFactory::create([2_000], successes: [0, 3, 7], failures: [0, 0, 0]), State::Closed, null];
        yield 'outcomes without a duration ignored' => [OutcomeCountsFactory::create([2_000], successes: [90, 1, 4], failures: [10, 0, 0]), State::Closed, [4, 5]];
        yield 'slow failures counted' => [OutcomeCountsFactory::create([2_000], successes: [0, 1, 0], failures: [0, 0, 4]), State::Closed, [4, 5]];
        yield 'slow for a lower threshold only' => [OutcomeCountsFactory::create([1_000, 2_000], successes: [0, 0, 5, 0], failures: [0, 0, 0, 0]), State::Closed, null];
        yield 'half-open, a single slow call' => [OutcomeCountsFactory::create([2_000], successes: [0, 0, 1], failures: [0, 0, 0]), State::HalfOpen, [1, 1]];
        yield 'half-open, no outcome with a duration' => [OutcomeCountsFactory::create([2_000], successes: [3, 0, 0], failures: [0, 0, 0]), State::HalfOpen, null];
    }

    /**
     * @param array{int, int}|null $expectedOutcomes matching and evaluated outcomes of the expected cause, null when not opening
     */
    #[DataProvider('openingProvider')]
    public function testOpening(OutcomeCounts $counts, State $state, ?array $expectedOutcomes): void
    {
        $rule = new SlowCallRateRule(slowCallDuration: 2.0, threshold: 80, minimumRequests: 5);

        $expected = $expectedOutcomes === null ? null : new OpeningCause($rule, ...$expectedOutcomes);
        self::assertEquals($expected, $rule->findOpeningCause($counts, $state));
    }

    /**
     * @return iterable<string, array{float, int}>
     */
    public static function durationThresholdProvider(): iterable
    {
        yield 'seconds' => [2.0, 2_000];
        yield 'rounded to the millisecond' => [0.0024, 2];
    }

    #[DataProvider('durationThresholdProvider')]
    public function testDecidesOnTheSlowCallDuration(float $slowCallDuration, int $expectedThreshold): void
    {
        $rule = new SlowCallRateRule($slowCallDuration, threshold: 80, minimumRequests: 5);

        self::assertSame([$expectedThreshold], $rule->getDurationThresholds());
    }

    /**
     * @return iterable<string, array{float, int, int}>
     */
    public static function invalidRuleProvider(): iterable
    {
        yield 'slow call duration below 1 millisecond' => [0.0009, 80, 10];
        yield 'infinite slow call duration' => [\INF, 80, 10];
        yield 'threshold of 0' => [2.0, 0, 10];
        yield 'threshold above 100' => [2.0, 101, 10];
        yield 'minimum requests of 0' => [2.0, 80, 0];
    }

    #[DataProvider('invalidRuleProvider')]
    public function testInvalidRule(float $slowCallDuration, int $threshold, int $minimumRequests): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SlowCallRateRule($slowCallDuration, $threshold, $minimumRequests);
    }
}
