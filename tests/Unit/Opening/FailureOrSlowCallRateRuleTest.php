<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Tests\Unit\Opening;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zeusi\CircuitBreaker\Counting\OutcomeCounts;
use Zeusi\CircuitBreaker\Exception\InvalidArgumentException;
use Zeusi\CircuitBreaker\Opening\FailureOrSlowCallRateRule;
use Zeusi\CircuitBreaker\Opening\OpeningCause;
use Zeusi\CircuitBreaker\State;
use Zeusi\CircuitBreaker\Tests\Fixtures\OutcomeCountsFactory;

final class FailureOrSlowCallRateRuleTest extends TestCase
{
    /**
     * @return iterable<string, array{OutcomeCounts, State, array{int, int}|null}>
     */
    public static function openingProvider(): iterable
    {
        // bands: without a duration, below 2 seconds, from 2 seconds
        yield 'below the minimum requests' => [OutcomeCountsFactory::create([2_000], successes: [0, 0, 3], failures: [0, 0, 0]), State::Closed, null];
        yield 'failures and slow successes together' => [OutcomeCountsFactory::create([2_000], successes: [0, 1, 2], failures: [1, 0, 0]), State::Closed, [3, 4]];
        yield 'a slow failure counted once' => [OutcomeCountsFactory::create([2_000], successes: [0, 2, 0], failures: [0, 0, 2]), State::Closed, [2, 4]];
        yield 'successes without a duration are not slow' => [OutcomeCountsFactory::create([2_000], successes: [2, 0, 2], failures: [0, 0, 0]), State::Closed, [2, 4]];
        yield 'below the threshold' => [OutcomeCountsFactory::create([2_000], successes: [0, 5, 0], failures: [1, 0, 0]), State::Closed, null];
        yield 'half-open, a single slow success' => [OutcomeCountsFactory::create([2_000], successes: [0, 0, 1], failures: [0, 0, 0]), State::HalfOpen, [1, 1]];
        yield 'half-open, a single fast success' => [OutcomeCountsFactory::create([2_000], successes: [0, 1, 0], failures: [0, 0, 0]), State::HalfOpen, null];
    }

    /**
     * @param array{int, int}|null $expectedOutcomes matching and evaluated outcomes of the expected cause, null when not opening
     */
    #[DataProvider('openingProvider')]
    public function testOpening(OutcomeCounts $counts, State $state, ?array $expectedOutcomes): void
    {
        $rule = new FailureOrSlowCallRateRule(slowCallDuration: 2.0, threshold: 50, minimumRequests: 4);

        $expected = $expectedOutcomes === null ? null : new OpeningCause($rule, ...$expectedOutcomes);
        self::assertEquals($expected, $rule->findOpeningCause($counts, $state));
    }

    public function testDecidesOnTheSlowCallDuration(): void
    {
        $rule = new FailureOrSlowCallRateRule(slowCallDuration: 2.0, threshold: 50, minimumRequests: 4);

        self::assertSame([2_000], $rule->getDurationThresholds());
    }

    /**
     * @return iterable<string, array{float, int, int}>
     */
    public static function invalidRuleProvider(): iterable
    {
        yield 'slow call duration below 1 millisecond' => [0.0009, 50, 10];
        yield 'threshold of 0' => [2.0, 0, 10];
        yield 'threshold above 100' => [2.0, 101, 10];
        yield 'minimum requests of 0' => [2.0, 50, 0];
    }

    #[DataProvider('invalidRuleProvider')]
    public function testInvalidRule(float $slowCallDuration, int $threshold, int $minimumRequests): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FailureOrSlowCallRateRule($slowCallDuration, $threshold, $minimumRequests);
    }
}
