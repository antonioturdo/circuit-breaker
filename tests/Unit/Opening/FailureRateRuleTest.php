<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Tests\Unit\Opening;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zeusi\CircuitBreaker\Counting\OutcomeCounts;
use Zeusi\CircuitBreaker\Exception\InvalidArgumentException;
use Zeusi\CircuitBreaker\Opening\FailureRateRule;
use Zeusi\CircuitBreaker\Opening\OpeningCause;
use Zeusi\CircuitBreaker\State;
use Zeusi\CircuitBreaker\Tests\Fixtures\OutcomeCountsFactory;

final class FailureRateRuleTest extends TestCase
{
    /**
     * @return iterable<string, array{OutcomeCounts, State, array{int, int}|null}>
     */
    public static function openingProvider(): iterable
    {
        yield 'below the minimum requests' => [OutcomeCountsFactory::createWithoutDurations(0, 9), State::Closed, null];
        yield 'below the threshold' => [OutcomeCountsFactory::createWithoutDurations(6, 4), State::Closed, null];
        yield 'at the threshold' => [OutcomeCountsFactory::createWithoutDurations(5, 5), State::Closed, [5, 10]];
        yield 'half-open, a single failure' => [OutcomeCountsFactory::createWithoutDurations(0, 1), State::HalfOpen, [1, 1]];
        yield 'half-open, below the threshold' => [OutcomeCountsFactory::createWithoutDurations(2, 1), State::HalfOpen, null];
        yield 'half-open, no outcome' => [OutcomeCountsFactory::createWithoutDurations(0, 0), State::HalfOpen, null];
    }

    /**
     * @param array{int, int}|null $expectedOutcomes matching and evaluated outcomes of the expected cause, null when not opening
     */
    #[DataProvider('openingProvider')]
    public function testOpening(OutcomeCounts $counts, State $state, ?array $expectedOutcomes): void
    {
        $rule = new FailureRateRule(threshold: 50, minimumRequests: 10);

        $expected = $expectedOutcomes === null ? null : new OpeningCause($rule, ...$expectedOutcomes);
        self::assertEquals($expected, $rule->findOpeningCause($counts, $state));
    }

    public function testDecidesOnNoDuration(): void
    {
        self::assertSame([], (new FailureRateRule(threshold: 50, minimumRequests: 10))->getDurationThresholds());
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function invalidRuleProvider(): iterable
    {
        yield 'threshold of 0' => [0, 10];
        yield 'threshold above 100' => [101, 10];
        yield 'minimum requests of 0' => [50, 0];
    }

    #[DataProvider('invalidRuleProvider')]
    public function testInvalidRule(int $threshold, int $minimumRequests): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FailureRateRule($threshold, $minimumRequests);
    }
}
